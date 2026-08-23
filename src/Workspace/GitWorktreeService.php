<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Workspace;

use RuntimeException;
use voku\AgentLoopRunner\Git\GitCommand;

final readonly class GitWorktreeService
{
    public function __construct(private GitCommand $git)
    {
    }

    public function assertRepository(string $projectRoot): string
    {
        $canonical = realpath($projectRoot);
        if (!is_string($canonical)) {
            throw new RuntimeException('Project root cannot be resolved: ' . $projectRoot);
        }
        $canonical = $this->normalize($canonical);
        $topLevel = trim($this->git->requireSuccess($canonical, ['rev-parse', '--show-toplevel'])->stdout);
        $resolvedTop = realpath($topLevel);
        if (!is_string($resolvedTop) || $this->normalize($resolvedTop) !== $canonical) {
            throw new RuntimeException('Runner project root is not the Git worktree root: ' . $canonical);
        }

        return $canonical;
    }

    public function ensureVolatilePathsIgnored(string $projectRoot): void
    {
        $root = $this->assertRepository($projectRoot);
        foreach ([
            '.agent-loop-runner/runtime/',
            '.agent-loop-runner/worktrees/',
            '.agent-loop-runner/logs/',
        ] as $path) {
            $check = $this->git->run($root, ['check-ignore', '--quiet', '--', $path]);
            if ($check->successful()) {
                continue;
            }
            $common = $this->commonGitDirectory($root);
            $exclude = $common . '/info/exclude';
            $directory = dirname($exclude);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create Git info directory: ' . $directory);
            }
            $contents = is_file($exclude) ? (string) file_get_contents($exclude) : '';
            if (!str_contains("\n" . $contents . "\n", "\n" . $path . "\n")) {
                if (file_put_contents($exclude, rtrim($contents, "\r\n") . ($contents !== '' ? "\n" : '') . $path . "\n") === false) {
                    throw new RuntimeException('Unable to update repository-local Git excludes: ' . $exclude);
                }
            }
        }
    }

    public function create(string $projectRoot, string $worktreePath, string $baseCommit): string
    {
        $root = $this->assertRepository($projectRoot);
        if (preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('Workspace creation requires an exact Git base commit.');
        }
        $this->git->requireSuccess($root, ['cat-file', '-e', $baseCommit . '^{commit}']);
        $this->ensureVolatilePathsIgnored($root);

        $parent = dirname($worktreePath);
        if (!is_dir($parent) && !mkdir($parent, 0o775, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to create worktree parent directory: ' . $parent);
        }
        if (file_exists($worktreePath)) {
            return $this->assertExisting($root, $worktreePath, $baseCommit);
        }

        $this->git->requireSuccess($root, ['worktree', 'add', '--detach', '--', $worktreePath, $baseCommit], 120);

        return $this->assertExisting($root, $worktreePath, $baseCommit);
    }

    public function assertExisting(string $projectRoot, string $worktreePath, string $baseCommit): string
    {
        $root = $this->assertRepository($projectRoot);
        $canonical = realpath($worktreePath);
        if (!is_string($canonical) || !is_dir($canonical)) {
            throw new RuntimeException('Expected Run worktree does not exist: ' . $worktreePath);
        }
        $canonical = $this->normalize($canonical);
        $topLevel = trim($this->git->requireSuccess($canonical, ['rev-parse', '--show-toplevel'])->stdout);
        $resolvedTop = realpath($topLevel);
        if (!is_string($resolvedTop) || $this->normalize($resolvedTop) !== $canonical) {
            throw new RuntimeException('Run workspace is not a Git worktree root: ' . $canonical);
        }
        if ($this->commonGitDirectory($root) !== $this->commonGitDirectory($canonical)) {
            throw new RuntimeException('Run workspace belongs to a different Git repository: ' . $canonical);
        }
        $head = trim($this->git->requireSuccess($canonical, ['rev-parse', '--verify', 'HEAD'])->stdout);
        if (!hash_equals($baseCommit, $head)) {
            throw new RuntimeException(sprintf(
                'Run workspace HEAD drifted from governed base commit: expected %s, got %s.',
                $baseCommit,
                $head,
            ));
        }

        return $canonical;
    }

    public function statusPorcelain(string $worktreePath): string
    {
        return $this->git->requireSuccess($worktreePath, ['status', '--porcelain=v1', '-z'])->stdout;
    }

    public function remove(string $projectRoot, string $worktreePath): void
    {
        $root = $this->assertRepository($projectRoot);
        if (!file_exists($worktreePath)) {
            $this->git->run($root, ['worktree', 'prune']);
            return;
        }
        $status = $this->statusPorcelain($worktreePath);
        if ($status !== '') {
            throw new RuntimeException('Refusing to remove dirty Run workspace: ' . $worktreePath);
        }
        $this->git->requireSuccess($root, ['worktree', 'remove', '--', $worktreePath], 120);
        $this->git->run($root, ['worktree', 'prune']);
    }

    private function commonGitDirectory(string $workingDirectory): string
    {
        $path = trim($this->git->requireSuccess($workingDirectory, ['rev-parse', '--git-common-dir'])->stdout);
        if (!str_starts_with($path, '/')) {
            $path = rtrim($workingDirectory, '/\\') . '/' . $path;
        }
        $resolved = realpath($path);
        if (!is_string($resolved)) {
            throw new RuntimeException('Unable to resolve Git common directory from ' . $workingDirectory . '.');
        }

        return $this->normalize($resolved);
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
