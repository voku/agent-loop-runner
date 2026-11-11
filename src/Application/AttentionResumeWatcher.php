<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionProjection;

/**
 * Opt-in foreground wait for one pending, Loop-owned Attention.
 *
 * Owner resolution is observed, never decided here. The resume callback must
 * validate the accepted projection UNDER Runner's per-task execution lock.
 */
final readonly class AttentionResumeWatcher
{
    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param Closure(string): ExecutionProjection $observe Fresh owner projection
     * @param Closure(string, ExecutionProjection): ExecutionProjection $resume Locked, expected-owner resume
     * @param Closure(int): void $pause Bounded sleep in whole seconds
     * @param (Closure(): int)|null $clock Monotonic nanoseconds; injectable for deterministic tests
     */
    public function __construct(
        private Closure $observe,
        private Closure $resume,
        private Closure $pause,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => hrtime(true);
    }

    /**
     * @param ExecutionProjection|null $waiting Snapshot obtained from the prior locked WAITING exception.
     *                                          Standalone watch must pass null and observe a pending Attention.
     */
    public function watch(
        string $taskId,
        int $intervalSeconds = 4,
        int $maxWaitSeconds = 900,
        ?ExecutionProjection $waiting = null,
    ): ExecutionProjection {
        if ($intervalSeconds < 1 || $intervalSeconds > 60 || $maxWaitSeconds < 1 || $maxWaitSeconds > 86400) {
            throw new InvalidArgumentException('Watch interval must be 1..60 seconds and timeout must be 1..86400 seconds.');
        }

        // The deadline covers all read and sleep time, not just requested sleep.
        $deadline = ($this->clock)() + $maxWaitSeconds * 1_000_000_000;
        $initial = $waiting ?? ($this->observe)($taskId);
        $pending = $initial->attention;
        if ($initial->taskId !== $taskId) {
            throw new RuntimeException('STALE_RUN: owner projected a different Task identity.');
        }
        if ($pending === null || $initial->currentStageId === null) {
            throw new RuntimeException('TRANSITION_REJECTED: watch requires an already-pending, stage-bound Attention.');
        }

        // A prior run/resume may have observed WAITING just before a human resolved
        // it. Re-read immediately in that case instead of rejecting or sleeping.
        $refreshImmediately = $waiting !== null;
        while (true) {
            if (!$refreshImmediately) {
                $remaining = $deadline - ($this->clock)();
                if ($remaining <= 0) {
                    throw new RuntimeException('WATCH_TIMEOUT: Attention was not resolved within the configured waiting window.');
                }
                $seconds = min($intervalSeconds, max(1, (int) ceil($remaining / 1_000_000_000)));
                ($this->pause)($seconds);
            }
            $refreshImmediately = false;

            if (($this->clock)() >= $deadline) {
                throw new RuntimeException('WATCH_TIMEOUT: Attention was not resolved within the configured waiting window.');
            }
            $current = ($this->observe)($taskId);
            if (($this->clock)() >= $deadline) {
                throw new RuntimeException('WATCH_TIMEOUT: Attention was not resolved within the configured waiting window.');
            }

            if ($current->taskId !== $initial->taskId
                || $current->runId !== $initial->runId
                || $current->contractRevision !== $initial->contractRevision
                || $current->profile !== $initial->profile
                || !hash_equals($initial->executionPlanDigest, $current->executionPlanDigest)
                || !hash_equals($initial->candidateRevision, $current->candidateRevision)) {
                throw new RuntimeException('STALE_RUN: the watched Task, Run, Contract, plan, or candidate changed; refusing automatic continuation.');
            }

            if ($current->attention !== null) {
                if ($current->attention->id !== $pending->id) {
                    throw new RuntimeException('WAITING_FOR_ATTENTION: a different Attention is pending; refusing to continue.');
                }

                continue;
            }

            if ($current->complete()) {
                return $current; // No provider if another actor completed this Run.
            }

            if ($current->currentStageId === $initial->currentStageId
                && $current->currentAttempt === $initial->currentAttempt) {
                throw new RuntimeException('TRANSITION_REJECTED: Attention disappeared without an authorized stage/attempt transition.');
            }

            // Even after this poll, the Run can change before the lock is acquired.
            // Pass the accepted snapshot into the locked resume and re-check it there.
            return ($this->resume)($taskId, $current);
        }
    }
}
