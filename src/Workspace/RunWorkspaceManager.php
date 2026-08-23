<?php

declare(strict_types=1);
namespace voku\AgentLoopRunner\Workspace;

use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;

final readonly class RunWorkspaceManager
{
    public function __construct(
        private RunnerLayout $layout,
        private GitWorktreeService $worktrees,
        private WorkspaceCandidateHasher $hasher,
    ) {}

    public function projectRoot(): string { return $this->layout->projectRoot(); }

    public function acquire(string $taskId, string $runId, string $baseCommit, string $stageId, int $attempt, bool $mayMutate, string $candidateRevision): ManagedWorkspace
    {
        $path = $this->layout->worktree($taskId, $runId);
        $canonical = $this->worktrees->create($this->layout->projectRoot(), $path, $baseCommit);
        $lease = new WorkspaceLease($taskId, $runId, $canonical, $baseCommit, $stageId, $attempt, $mayMutate);
        $actual = $this->candidateRevision($canonical, $baseCommit);
        if (!hash_equals($candidateRevision, $actual)) {
            throw new RuntimeException('STALE_WORKSPACE: candidate revision does not match authoritative execution bundle.');
        }
        $lock = $mayMutate ? $this->mutationLock($canonical) : null;
        return new ManagedWorkspace($lease, $actual, $lock);
    }

    public function assertLease(WorkspaceLease $lease, string $taskId, string $runId, string $stageId, int $attempt, bool $mayMutate): void
    {
        if (!hash_equals($lease->taskId, $taskId) || !hash_equals($lease->runId, $runId)
            || !hash_equals($lease->ownerStageId, $stageId) || $lease->attempt !== $attempt || $lease->mayMutate !== $mayMutate) {
            throw new RuntimeException('STALE_WORKSPACE: workspace lease identity mismatch.');
        }
        $expected = $this->layout->worktree($taskId, $runId);
        $canonicalExpected = realpath($expected);
        $canonicalLease = realpath($lease->path);
        if (!is_string($canonicalExpected) || !is_string($canonicalLease) || $canonicalExpected !== $canonicalLease) {
            throw new RuntimeException('STALE_WORKSPACE: workspace lease path mismatch.');
        }
        $this->worktrees->assertExisting($this->layout->projectRoot(), $lease->path, $lease->baseCommit);
    }

    public function candidateAfter(ManagedWorkspace $workspace): string
    {
        $current = $this->candidateRevision($workspace->lease->path, $workspace->lease->baseCommit);
        if (!$workspace->lease->mayMutate && !hash_equals($workspace->initialCandidateRevision, $current)) {
            throw new RuntimeException('STALE_WORKSPACE: read-only stage modified candidate; evidence was preserved.');
        }
        return $current;
    }

    public function cleanup(string $taskId, string $runId): void
    {
        $this->worktrees->remove($this->layout->projectRoot(), $this->layout->worktree($taskId, $runId));
    }

    private function candidateRevision(string $path, string $baseCommit): string
    {
        if ($this->worktrees->statusPorcelain($path) === '') return $baseCommit;
        return $this->hasher->hash($path, $baseCommit);
    }

    private function mutationLock(string $workspace): WorkspaceMutationLock
    {
        $path = dirname($workspace) . '/.' . basename($workspace) . '.mutation.lock';
        $handle = fopen($path, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('STALE_WORKSPACE: another mutating stage owns this Run workspace.');
        }
        return new WorkspaceMutationLock($handle);
    }
}
