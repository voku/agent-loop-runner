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
