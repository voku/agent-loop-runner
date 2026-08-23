<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Workspace;

use JsonException;
use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;

final readonly class WorkspaceLeaseStore
{
    public function __construct(private RunnerLayout $layout)
    {
    }

    public function load(string $taskId, string $runId): ?WorkspaceLease
    {
        $path = $this->layout->workspaceLease($taskId, $runId);
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read Runner workspace lease: ' . $path);
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Runner workspace lease is invalid JSON: ' . $path, 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Runner workspace lease must be a JSON object: ' . $path);
        }

        return new WorkspaceLease(
            $this->string($data, 'task_id'),
            $this->string($data, 'run_id'),
            $this->string($data, 'path'),
            $this->string($data, 'base_commit'),
            $this->string($data, 'owner_stage_id'),
            $this->integer($data, 'attempt'),
            $this->boolean($data, 'may_mutate'),
        );
    }

    public function save(WorkspaceLease $lease): void
    {
        $path = $this->layout->workspaceLease($lease->taskId, $lease->runId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Runner workspace lease directory: ' . $directory);
        }
        try {
            $json = json_encode($lease->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Runner workspace lease.', 0, $exception);
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish Runner workspace lease atomically: ' . $path);
        }
        @chmod($path, 0600);
    }

    public function remove(WorkspaceLease $lease): void
    {
        $path = $this->layout->workspaceLease($lease->taskId, $lease->runId);
        $current = $this->load($lease->taskId, $lease->runId);
        if ($current === null) {
            return;
        }
        if ($current->toArray() !== $lease->toArray()) {
            throw new RuntimeException('STALE_WORKSPACE: refusing to remove a workspace lease owned by another attempt.');
        }
        if (!unlink($path) && is_file($path)) {
            throw new RuntimeException('Unable to remove Runner workspace lease: ' . $path);
        }
    }

    /** @param array<array-key, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Runner workspace lease requires non-empty string field ' . $key . '.');
        }

        return trim($value);
    }

    /** @param array<array-key, mixed> $data */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException('Runner workspace lease requires integer field ' . $key . '.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException('Runner workspace lease requires boolean field ' . $key . '.');
        }

        return $value;
    }
}
