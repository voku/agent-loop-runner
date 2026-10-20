<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Workspace;

use RuntimeException;
use voku\AgentLoop\Execution\StageArtifactObservation;
use voku\AgentLoop\Execution\StageExecutionBundle;

final readonly class WorkspaceArtifactObserver
{
    /**
     * @param list<non-empty-string> $requestedReferences
     * @return list<StageArtifactObservation>
     */
    public function observe(
        StageExecutionBundle $bundle,
        string $worktreePath,
        string $candidateRevision,
        array $requestedReferences,
    ): array {
        $root = realpath($worktreePath);
        if (!is_string($root)) {
            throw new RuntimeException('INVALID_STAGE_RESULT: Run workspace cannot be resolved for artifact observation.');
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');

        $observations = [];
        $seen = [];
        foreach ($requestedReferences as $reference) {
            if (isset($seen[$reference])) {
                continue;
            }
            $seen[$reference] = true;
            if (str_starts_with($reference, 'deleted-file:')) {
                $relativePath = $this->relativePath(substr($reference, strlen('deleted-file:')));
                $requestedPath = $root . '/' . $relativePath;
                if (file_exists($requestedPath) || is_link($requestedPath)) {
                    throw new RuntimeException('INVALID_STAGE_RESULT: deleted-file evidence still exists in the candidate workspace: ' . $reference);
                }
                if ($bundle->baseCommit === null) {
                    throw new RuntimeException('INVALID_STAGE_RESULT: deleted-file evidence requires a governed Git base.');
                }
                $observations[] = new StageArtifactObservation(
                    $bundle->taskId,
                    $bundle->runId,
                    $bundle->contractRevision,
                    $bundle->executionPlanDigest,
                    $bundle->stageId,
                    $bundle->attempt,
                    $candidateRevision,
                    'git-base-file:' . $relativePath,
                    $this->baseFileDigest($root, $bundle->baseCommit, $relativePath),
                );
                continue;
            }

            $relativePath = $this->relativePath($reference);
            $requestedPath = $root . '/' . $relativePath;
            if (!file_exists($requestedPath)) {
                // Real providers cite evidence as "path:line", the same convention
                // grep, compilers and editors use. The literal path always wins;
                // the citation suffix is only dropped as a fallback, and the
                // remaining path still passes every guard below.
                $cited = $this->withoutLineCitation($relativePath);
                if ($cited !== null && file_exists($root . '/' . $cited)) {
                    $relativePath = $cited;
                    $requestedPath = $root . '/' . $cited;
                }
            }
            if (is_link($requestedPath)) {
                throw new RuntimeException('INVALID_STAGE_RESULT: artifact references must not be symlinks: ' . $reference);
            }
            $resolved = realpath($requestedPath);
            if (!is_string($resolved)) {
                throw new RuntimeException('INVALID_STAGE_RESULT: requested artifact does not exist: ' . $reference);
            }
            $resolved = str_replace('\\', '/', $resolved);
            if (!str_starts_with($resolved, $root . '/') || !is_file($resolved)) {
                throw new RuntimeException('INVALID_STAGE_RESULT: requested artifact is not a regular file inside the Run workspace: ' . $reference);
            }
            $digest = hash_file('sha256', $resolved);
            if (!is_string($digest)) {
                throw new RuntimeException('INVALID_STAGE_RESULT: unable to hash requested artifact: ' . $reference);
            }
            $observations[] = new StageArtifactObservation(
                $bundle->taskId,
                $bundle->runId,
                $bundle->contractRevision,
                $bundle->executionPlanDigest,
                $bundle->stageId,
                $bundle->attempt,
                $candidateRevision,
                'workspace-file:' . $relativePath,
                'sha256:' . $digest,
            );
        }

        return $observations;
    }

    private function baseFileDigest(string $root, string $baseCommit, string $relativePath): string
    {
        if (preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('INVALID_STAGE_RESULT: deleted-file evidence base commit is not exact.');
        }
        $process = proc_open(
            ['git', '--no-replace-objects', 'cat-file', 'blob', $baseCommit . ':' . $relativePath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('INVALID_STAGE_RESULT: unable to inspect deleted-file evidence in the governed base.');
        }
        fclose($pipes[0]);
        $hash = hash_init('sha256');
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 1024 * 1024);
            if ($chunk === false) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_terminate($process);
                proc_close($process);
                throw new RuntimeException('INVALID_STAGE_RESULT: unable to read deleted-file evidence from the governed base.');
            }
            hash_update($hash, $chunk);
        }
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException(
                'INVALID_STAGE_RESULT: deleted-file path is not present in the governed base: '
                . trim(is_string($error) ? $error : ''),
            );
        }

        return 'sha256:' . hash_final($hash);
    }

    /**
     * Drops a trailing "path:line", "path:line:column" or "path:line-line"
     * citation. Returns null when the reference carries no such suffix, so the
     * caller keeps rejecting a genuinely missing artifact.
     *
     * @return non-empty-string|null
     */
    private function withoutLineCitation(string $relativePath): ?string
    {
        if (preg_match('/^(?<path>.+?):\\d+(?:[:-]\\d+)?$/', $relativePath, $matches) !== 1) {
            return null;
        }
        // The pattern requires at least one character before the citation, so
        // the captured path is always non-empty here.
        return $matches['path'];
    }

    /** @return non-empty-string */
    private function relativePath(string $reference): string
    {
        if (str_starts_with($reference, 'workspace-file:')) {
            $reference = substr($reference, strlen('workspace-file:'));
        }

        if ($reference === ''
            || str_starts_with($reference, '/')
            || str_contains($reference, "\0")
            || str_contains($reference, '\\')) {
            throw new RuntimeException('INVALID_STAGE_RESULT: artifact reference must be a canonical workspace-relative path.');
        }
        $segments = explode('/', $reference);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('INVALID_STAGE_RESULT: artifact reference contains an unsafe path segment: ' . $reference);
            }
        }

        return $reference;
    }
}
