<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentLoopRunner\RunnerLayout;

final readonly class RuntimeJournalStore
{
    public function __construct(private RunnerLayout $layout)
    {
    }

    public function load(string $taskId): ?RuntimeJournal
    {
        return $this->loadPath($this->layout->runtime($taskId));
    }

    public function save(RuntimeJournal $journal): void
    {
        $this->withLock($journal->taskId, function () use ($journal): void {
            $this->write($journal);
        });
    }

    public function create(RuntimeJournal $journal): void
    {
        $this->withLock($journal->taskId, function () use ($journal): void {
            if ($this->loadPath($this->layout->runtime($journal->taskId)) !== null) {
                throw new RuntimeException('STALE_RUN: Runner runtime journal already exists for task ' . $journal->taskId . '.');
            }
            $this->write($journal);
        });
    }

    public function transition(RuntimeJournal $expected, RuntimeJournal $next): void
    {
        if ($expected->taskId !== $next->taskId || $expected->submissionId !== $next->submissionId) {
            throw new RuntimeException('STALE_RUN: runtime transition cannot change task or submission identity.');
        }

        $this->withLock($expected->taskId, function () use ($expected, $next): void {
            $current = $this->loadPath($this->layout->runtime($expected->taskId));
            if ($current === null || $current->toArray() !== $expected->toArray()) {
                throw new RuntimeException('STALE_RUN: Runner runtime journal changed concurrently.');
            }
            $this->write($next);
        });
    }

    public function deleteIf(RuntimeJournal $expected): void
    {
        $this->withLock($expected->taskId, function () use ($expected): void {
            $path = $this->layout->runtime($expected->taskId);
            $current = $this->loadPath($path);
            if ($current === null) {
                return;
            }
            if ($current->toArray() !== $expected->toArray()) {
                throw new RuntimeException('STALE_RUN: refusing to delete a concurrently changed Runner runtime journal.');
            }
            $this->unlinkPath($path);
        });
    }

    public function delete(string $taskId): void
    {
        $this->withLock($taskId, function () use ($taskId): void {
            $this->unlinkPath($this->layout->runtime($taskId));
        });
    }

    private function write(RuntimeJournal $journal): void
    {
        $path = $this->layout->runtime($journal->taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Runner runtime directory: ' . $directory);
        }

        try {
            $json = json_encode($journal->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Runner runtime journal.', 0, $exception);
        }

        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to create Runner runtime temporary file: ' . $temporary);
        }

        try {
            if (fwrite($handle, $json) !== strlen($json)) {
                throw new RuntimeException('Unable to write complete Runner runtime journal: ' . $temporary);
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush Runner runtime journal: ' . $temporary);
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Unable to fsync Runner runtime journal: ' . $temporary);
            }
            fclose($handle);
            $handle = null;

            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to protect Runner runtime journal: ' . $temporary);
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish Runner runtime journal atomically: ' . $path);
            }
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw $exception;
        }
    }

    private function loadPath(string $path): ?RuntimeJournal
    {
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read Runner runtime journal: ' . $path);
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Runner runtime journal is invalid JSON: ' . $path, 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Runner runtime journal must be a JSON object: ' . $path);
        }

        /** @var array<string, mixed> $data */
        return RuntimeJournal::fromArray($data);
    }

    private function unlinkPath(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        if (!unlink($path) && is_file($path)) {
            throw new RuntimeException('Unable to remove Runner runtime journal: ' . $path);
        }
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function withLock(string $taskId, Closure $callback): mixed
    {
        $path = $this->layout->runtime($taskId) . '.lock';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Runner runtime lock directory: ' . $directory);
        }
        $lock = fopen($path, 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to acquire Runner runtime lock: ' . $path);
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
