<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;

/** Prevents two Runner processes from executing the same governed task concurrently. */
final class RunExecutionLock
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function acquire(RunnerLayout $layout, string $taskId): self
    {
        $path = $layout->executionLock($taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Runner execution lock directory.');
        }

        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open Runner execution lock.');
        }
        @chmod($path, 0o600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('STALE_RUN: another Runner process is already executing this task.');
        }

        return new self($handle);
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
