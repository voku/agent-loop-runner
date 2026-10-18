<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

/**
 * Read-only eligibility of a recorded provider process for cancellation.
 *
 * One definition shared by the `cancel` action and the `status` controls projection, so the control that is
 * advertised is the control that can work. The result is a snapshot, not a guarantee: the process can exit
 * between this check and a later signal.
 */
final readonly class CancellableProcess
{
    /**
     * The first reason the recorded process cannot be cancelled now, or null when it can.
     *
     * @param array<string, mixed> $process The `process` evidence of a `process_started` attempt.
     */
    public static function problem(array $process): ?string
    {
        $pid = $process['pid'] ?? null;
        $fingerprint = $process['process_fingerprint'] ?? null;
        if (!is_int($pid)
            || !is_string($fingerprint)
            || !hash_equals($fingerprint, ProcessIdentity::fingerprint($pid) ?? '')) {
            return 'owned process identity is stale.';
        }
        if (PHP_OS_FAMILY === 'Windows'
            || !function_exists('posix_kill')
            || !function_exists('posix_getpgid')) {
            return 'process-group cancellation is unavailable.';
        }
        $groupId = posix_getpgid($pid);
        if (!is_int($groupId) || $groupId !== $pid) {
            return 'owned process is not in an isolated process group.';
        }

        return null;
    }
}
