<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Workspace;

use HashContext;
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
        $head = trim($this->git->requireSuccess($worktreePath, ['rev-parse', '--verify', 'HEAD'])->stdout);
        if (!hash_equals($baseCommit, $head)) {
            throw new RuntimeException(sprintf(
                'Candidate workspace HEAD changed: expected governed base %s, got %s. Agents must not commit inside the Run workspace.',
                $baseCommit,
                $head,
            ));
        }

        $trackedDiff = $this->git->requireSuccess(
            $worktreePath,
            ['diff', '--binary', '--no-ext-diff', '--full-index', 'HEAD', '--'],
            120,
        )->stdout;
        $untrackedRaw = $this->git->requireSuccess(
            $worktreePath,
            ['ls-files', '--others', '--exclude-standard', '-z'],
        )->stdout;
        $untracked = $this->nulList($untrackedRaw);
        sort($untracked, SORT_STRING);

        $hash = hash_init('sha256');
        hash_update($hash, "agent-loop-runner-candidate-v1\0");
        hash_update($hash, $baseCommit . "\0");
        hash_update($hash, strlen($trackedDiff) . "\0" . $trackedDiff);
        foreach ($untracked as $relativePath) {
            $absolute = $this->inside($worktreePath, $relativePath);
            hash_update($hash, "\0U\0" . strlen($relativePath) . "\0" . $relativePath);
            $this->updateFileEvidence($hash, $absolute);
        }

        return 'git-worktree-v1:' . $baseCommit . ':sha256:' . hash_final($hash);
    }

    /** @return list<non-empty-string> */
    private function nulList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = explode("\0", $raw);
        if (end($parts) === '') {
            array_pop($parts);
        }
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') {
                throw new RuntimeException('Git returned an invalid empty untracked path.');
            }
            $result[] = $part;
        }

        return $result;
    }

    private function inside(string $worktreePath, string $relativePath): string
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0")) {
            throw new RuntimeException('Git returned an invalid untracked path.');
        }
        $root = realpath($worktreePath);
        if (!is_string($root)) {
            throw new RuntimeException('Run workspace cannot be resolved for candidate hashing.');
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $candidate = $root . '/' . $relativePath;
        $parent = realpath(dirname($candidate));
        if (!is_string($parent)) {
            throw new RuntimeException('Untracked candidate parent cannot be resolved: ' . $relativePath);
        }
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if ($parent !== $root && !str_starts_with($parent, $root . '/')) {
            throw new RuntimeException('Untracked candidate path escapes the Run workspace: ' . $relativePath);
        }

        return $candidate;
    }

    private function updateFileEvidence(HashContext $hash, string $path): void
    {
        if (is_link($path)) {
            $target = readlink($path);
            if (!is_string($target)) {
                throw new RuntimeException('Unable to read candidate symlink: ' . $path);
            }
            $evidence = 'symlink:' . $target;
            hash_update($hash, "\0" . strlen($evidence) . "\0" . $evidence);
            return;
        }
        if (!is_file($path)) {
            throw new RuntimeException('Untracked candidate is not a regular file or symlink: ' . $path);
        }
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if (!is_int($size) || $handle === false) {
            throw new RuntimeException('Unable to read untracked candidate file: ' . $path);
        }
        hash_update($hash, "\0" . ($size + strlen('file:')) . "\0file:");
        $read = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read untracked candidate file: ' . $path);
                }
                $read += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($handle);
        }
        if ($read !== $size) {
            throw new RuntimeException('Untracked candidate changed while hashing: ' . $path);
        }
    }
}
