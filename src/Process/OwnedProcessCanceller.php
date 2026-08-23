<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

use RuntimeException;

final readonly class OwnedProcessCanceller
{
    public function cancel(int $pid, int $graceMilliseconds = 1000): bool
    {
        if ($pid < 1) {
            throw new RuntimeException('PROCESS_FAILED: owned process PID must be positive.');
        }
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_kill')) {
            throw new RuntimeException('PROCESS_FAILED: owned process cancellation requires POSIX process signaling.');
        }
        if (!$this->alive($pid)) {
            return false;
        }

        $term = defined('SIGTERM') ? SIGTERM : 15;
        $kill = defined('SIGKILL') ? SIGKILL : 9;
        if (!posix_kill(-$pid, $term) && !posix_kill($pid, $term)) {
            if (!$this->alive($pid)) {
                return false;
            }
            throw new RuntimeException('PROCESS_FAILED: unable to signal owned process ' . $pid . '.');
        }

        $deadline = hrtime(true) + ($graceMilliseconds * 1_000_000);
        while ($this->alive($pid) && hrtime(true) < $deadline) {
            usleep(20_000);
        }
        if ($this->alive($pid)) {
            if (!posix_kill(-$pid, $kill) && !posix_kill($pid, $kill) && $this->alive($pid)) {
                throw new RuntimeException('PROCESS_FAILED: unable to kill owned process ' . $pid . '.');
            }
        }

        return true;
    }

    public function alive(int $pid): bool
    {
        if ($pid < 1 || PHP_OS_FAMILY === 'Windows' || !function_exists('posix_kill')) {
            return false;
        }

        return posix_kill($pid, 0);
    }
}
