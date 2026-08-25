<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Workspace;

use RuntimeException;
use voku\AgentLoopRunner\Git\GitCommand;

final readonly class WorkspaceCandidateHasher
{
    public function __construct(private GitCommand $git)
    {
    }

    public function hash(string $worktreePath, string $baseCommit): string
    {
        if (preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('Candidate hashing requires an exact Git base commit.');
        }
        $this->assertHead($worktreePath, $baseCommit);

        $indexPath = tempnam(sys_get_temp_dir(), 'agent-loop-runner-index-');
        if (!is_string($indexPath)) {
            throw new RuntimeException('Unable to allocate private Git index for candidate observation.');
        }
        if (!unlink($indexPath)) {
            throw new RuntimeException('Unable to prepare private Git index for candidate observation.');
        }

        $environment = ['GIT_INDEX_FILE' => $indexPath];
        try {
            $this->git->requireSuccess($worktreePath, ['read-tree', $baseCommit], 30, $environment);
            $tree = $this->snapshotTree($worktreePath, $environment);
            $repeatedTree = $this->snapshotTree($worktreePath, $environment);
            if (!hash_equals($tree, $repeatedTree)) {
                throw new RuntimeException('STALE_WORKSPACE: Run workspace changed while candidate identity was being observed.');
            }
            $this->assertHead($worktreePath, $baseCommit);

            return 'git-tree-v1:' . $baseCommit . ':' . $tree;
        } finally {
            if (is_file($indexPath) && !unlink($indexPath)) {
                throw new RuntimeException('Unable to remove private Git index after candidate observation.');
            }
        }
    }

    /**
     * @param array<string, string> $environment
     * @return non-empty-string
     */
    private function snapshotTree(string $worktreePath, array $environment): string
    {
        $this->git->requireSuccess($worktreePath, ['add', '-A', '--', '.'], 120, $environment);
        $tree = trim($this->git->requireSuccess($worktreePath, ['write-tree'], 120, $environment)->stdout);
        if (preg_match('/^[0-9a-f]{40,64}$/', $tree) !== 1) {
            throw new RuntimeException('Git returned an invalid candidate tree object id.');
        }

        return $tree;
    }

    private function assertHead(string $worktreePath, string $baseCommit): void
    {
        $head = trim($this->git->requireSuccess($worktreePath, ['rev-parse', '--verify', 'HEAD'])->stdout);
        if (!hash_equals($baseCommit, $head)) {
            throw new RuntimeException(sprintf(
                'Candidate workspace HEAD changed: expected governed base %s, got %s. Agents must not commit inside the Run workspace.',
                $baseCommit,
                $head,
            ));
        }
    }
}
