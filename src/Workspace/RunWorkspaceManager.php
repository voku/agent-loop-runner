<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Workspace;

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

        $this->persistLease($lease);
        $this->assertCandidate($bundle, $lease);

        return $lease;
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

        if (!str_starts_with($bundle->candidateRevision, 'git-worktree-v1:')) {
            throw new RuntimeException('STALE_WORKSPACE: governed candidate revision has an unsupported identity.');
        }

        $actual = $this->candidateHasher->hash($lease->path, $lease->baseCommit);
        if (!hash_equals($bundle->candidateRevision, $actual)) {
            throw new RuntimeException('STALE_WORKSPACE: Run worktree candidate does not match the governed candidate revision.');
        }
    }

    public function release(WorkspaceLease $lease): void
    {
        $path = $this->layout->workspaceLease($lease->taskId, $lease->runId);
        $current = $this->readLease($path);
        if ($current === null) {
            return;
        }
        if ($current !== $lease->toArray()) {
            throw new RuntimeException('STALE_WORKSPACE: refusing to release a lease owned by a different stage or attempt.');
        }
        if (!unlink($path) && is_file($path)) {
            throw new RuntimeException('Unable to release Run workspace lease: ' . $path);
        }
    }

    private function persistLease(WorkspaceLease $lease): void
    {
        $path = $this->layout->workspaceLease($lease->taskId, $lease->runId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Runner workspace lease directory: ' . $directory);
        }

        $existing = $this->readLease($path);
        if ($existing !== null) {
            if ($existing !== $lease->toArray()) {
                throw new RuntimeException('STALE_WORKSPACE: Run workspace is leased by a different stage or attempt.');
            }

            return;
        }

        try {
            $json = json_encode($lease->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Run workspace lease.', 0, $exception);
        }

        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            $existing = $this->readLease($path);
            if ($existing === $lease->toArray()) {
                return;
            }
            throw new RuntimeException('STALE_WORKSPACE: concurrent Run workspace lease acquisition lost ownership.');
        }

        try {
            $written = fwrite($handle, $json);
            if ($written !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Unable to persist complete Run workspace lease: ' . $path);
            }
        } finally {
            fclose($handle);
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
        foreach ($decoded as $value) {
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new RuntimeException('STALE_WORKSPACE: Run workspace lease contains an invalid value.');
            }
        }

        /** @var array<string, int|string|bool> $decoded */
        return $decoded;
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
