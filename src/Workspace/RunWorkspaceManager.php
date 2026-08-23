<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Workspace;

use Closure;
use JsonException;
use RuntimeException;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoopRunner\RunnerLayout;

final readonly class RunWorkspaceManager
{
    public function __construct(
        private RunnerLayout $layout,
        private GitWorktreeService $worktrees,
        private WorkspaceCandidateHasher $candidateHasher,
    ) {
    }

    public function acquire(StageExecutionBundle $bundle): WorkspaceLease
    {
        $projectRoot = $this->layout->projectRoot();
        $bundleRoot = realpath($bundle->repositoryRoot);
        if (!is_string($bundleRoot)) {
            throw new RuntimeException('STALE_WORKSPACE: governed repository root cannot be resolved.');
        }
        $bundleRoot = rtrim(str_replace('\\', '/', $bundleRoot), '/');
        if (!hash_equals($projectRoot, $bundleRoot)) {
            throw new RuntimeException('STALE_WORKSPACE: Runner project root differs from the governed repository root.');
        }

        $baseCommit = $bundle->baseCommit;
        if ($baseCommit === null || preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('STALE_WORKSPACE: execution stage requires an exact governed Git base commit.');
        }

        return $this->withLeaseLock($bundle->taskId, $bundle->runId, function () use ($bundle, $projectRoot, $baseCommit): WorkspaceLease {
            $leasePath = $this->layout->workspaceLease($bundle->taskId, $bundle->runId);
            $existing = $this->readLease($leasePath);
            if ($existing !== null) {
                $expected = new WorkspaceLease(
                    $bundle->taskId,
                    $bundle->runId,
                    $this->layout->worktree($bundle->taskId, $bundle->runId),
                    $baseCommit,
                    $bundle->stageId,
                    $bundle->attempt,
                    $bundle->mayMutate,
                );
                if ($existing !== $expected->toArray()) {
                    throw new RuntimeException('STALE_WORKSPACE: Run workspace is leased by a different stage or attempt.');
                }
            }

            $path = $this->layout->worktree($bundle->taskId, $bundle->runId);
            $path = $this->worktrees->create($projectRoot, $path, $baseCommit);
            $lease = new WorkspaceLease(
                $bundle->taskId,
                $bundle->runId,
                $path,
                $baseCommit,
                $bundle->stageId,
                $bundle->attempt,
                $bundle->mayMutate,
            );

            if ($existing !== null && $existing !== $lease->toArray()) {
                throw new RuntimeException('STALE_WORKSPACE: persisted Run workspace path differs from the canonical worktree.');
            }

            $this->assertCandidate($bundle, $lease);
            if ($existing === null) {
                $this->persistLease($leasePath, $lease);
            }

            return $lease;
        });
    }

    public function assertCandidate(StageExecutionBundle $bundle, WorkspaceLease $lease): void
    {
        $this->assertLeaseMatchesBundle($lease, $bundle);

        if (hash_equals($lease->baseCommit, $bundle->candidateRevision)) {
            if ($this->worktrees->statusPorcelain($lease->path) !== '') {
                throw new RuntimeException('STALE_WORKSPACE: governed candidate is the base commit but the Run worktree is dirty.');
            }

            return;
        }

        if (preg_match(
            '/^git-worktree-v1:' . preg_quote($lease->baseCommit, '/') . ':sha256:[a-f0-9]{64}$/',
            $bundle->candidateRevision,
        ) !== 1) {
            throw new RuntimeException('STALE_WORKSPACE: governed candidate revision has an unsupported identity.');
        }

        $actual = $this->candidateHasher->hash($lease->path, $lease->baseCommit);
        if (!hash_equals($bundle->candidateRevision, $actual)) {
            throw new RuntimeException('STALE_WORKSPACE: Run worktree candidate does not match the governed candidate revision.');
        }
    }

    public function candidateRevisionAfter(StageExecutionBundle $bundle, WorkspaceLease $lease): string
    {
        $this->assertLeaseMatchesBundle($lease, $bundle);
        if (!$bundle->mayMutate) {
            $this->assertCandidate($bundle, $lease);

            return $bundle->candidateRevision;
        }

        if ($this->worktrees->statusPorcelain($lease->path) === '') {
            return $lease->baseCommit;
        }

        return $this->candidateHasher->hash($lease->path, $lease->baseCommit);
    }

    public function release(WorkspaceLease $lease): void
    {
        $this->withLeaseLock($lease->taskId, $lease->runId, function () use ($lease): null {
            $path = $this->layout->workspaceLease($lease->taskId, $lease->runId);
            $current = $this->readLease($path);
            if ($current === null) {
                return null;
            }
            if ($current !== $lease->toArray()) {
                throw new RuntimeException('STALE_WORKSPACE: refusing to release a lease owned by a different stage or attempt.');
            }
            if (!unlink($path) && is_file($path)) {
                throw new RuntimeException('Unable to release Run workspace lease: ' . $path);
            }

            return null;
        });
    }

    public function cleanup(string $taskId, string $runId): void
    {
        $this->withLeaseLock($taskId, $runId, function () use ($taskId, $runId): null {
            $leasePath = $this->layout->workspaceLease($taskId, $runId);
            if (is_file($leasePath)) {
                throw new RuntimeException('STALE_WORKSPACE: refusing cleanup while a Run workspace lease is active.');
            }

            $this->worktrees->remove(
                $this->layout->projectRoot(),
                $this->layout->worktree($taskId, $runId),
            );

            return null;
        });
    }

    private function persistLease(string $path, WorkspaceLease $lease): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Runner workspace lease directory: ' . $directory);
        }

        try {
            $json = json_encode($lease->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Run workspace lease.', 0, $exception);
        }

        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException('Unable to persist complete Run workspace lease: ' . $temporary);
        }
        if (!chmod($temporary, 0600)) {
            unlink($temporary);
            throw new RuntimeException('Unable to protect Run workspace lease permissions: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('Unable to publish Run workspace lease atomically: ' . $path);
        }
    }

    /** @return array<string, int|string|bool>|null */
    private function readLease(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read Run workspace lease: ' . $path);
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('STALE_WORKSPACE: Run workspace lease is corrupt.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('STALE_WORKSPACE: Run workspace lease has an invalid shape.');
        }
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || (!is_string($value) && !is_int($value) && !is_bool($value))) {
                throw new RuntimeException('STALE_WORKSPACE: Run workspace lease contains an invalid value.');
            }
        }

        /** @var array<string, int|string|bool> $decoded */
        return $decoded;
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function withLeaseLock(string $taskId, string $runId, Closure $callback): mixed
    {
        $path = $this->layout->workspaceLease($taskId, $runId) . '.lock';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Runner workspace lock directory: ' . $directory);
        }
        $lock = fopen($path, 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to acquire Runner workspace lease lock: ' . $path);
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assertLeaseMatchesBundle(WorkspaceLease $lease, StageExecutionBundle $bundle): void
    {
        if (
            $lease->taskId !== $bundle->taskId
            || $lease->runId !== $bundle->runId
            || $lease->ownerStageId !== $bundle->stageId
            || $lease->attempt !== $bundle->attempt
            || $lease->mayMutate !== $bundle->mayMutate
            || $bundle->baseCommit === null
            || !hash_equals($lease->baseCommit, $bundle->baseCommit)
        ) {
            throw new RuntimeException('STALE_WORKSPACE: workspace lease does not match the current governed stage.');
        }
    }
}
