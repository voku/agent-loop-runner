<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner;

use RuntimeException;

/** Owns every runner-private path; workflow state remains owned by agent-loop. */
final readonly class RunnerLayout
{
    public function __construct(private string $projectRoot)
    {
    }

    public function projectRoot(): string
    {
        $root = realpath($this->projectRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('Runner project root cannot be resolved: ' . $this->projectRoot);
        }

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    public function root(): string
    {
        return $this->projectRoot() . '/.agent-loop-runner';
    }

    public function config(): string
    {
        return $this->root() . '/config.json';
    }

    public function runtime(string $taskId): string
    {
        return $this->root() . '/runtime/' . $this->segment($taskId) . '.json';
    }

    public function worktree(string $taskId, string $runId): string
    {
        return $this->root() . '/worktrees/' . $this->segment($taskId) . '-' . substr(hash('sha256', $runId), 0, 12);
    }

    public function logDirectory(string $taskId, string $runId, string $stageId, int $attempt): string
    {
        return $this->root() . '/logs/'
            . $this->segment($taskId) . '/'
            . substr(hash('sha256', $runId), 0, 12) . '/'
            . $this->segment($stageId) . '/attempt-' . $attempt;
    }

    private function segment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = trim($value, '-.');
        if ($value === '') {
            return substr(hash('sha256', $value), 0, 16);
        }

        return substr($value, 0, 64);
    }
}
