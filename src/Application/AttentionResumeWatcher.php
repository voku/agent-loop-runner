<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionProjection;

/**
 * Opt-in foreground watcher for one already-pending, Loop-owned Attention.
 *
 * This class never resolves an Attention or invents stage authorization.
 * The resume callback must use Runner's existing locked reconciliation path.
 */
final readonly class AttentionResumeWatcher
{
    /**
     * @param Closure(string): ExecutionProjection $observe Fresh owner projection
     * @param Closure(string): ExecutionProjection $resume Runner's guarded resume
     * @param Closure(int): void $pause Bounded sleep in whole seconds
     */
    public function __construct(
        private Closure $observe,
        private Closure $resume,
        private Closure $pause,
    ) {
    }

    public function watch(string $taskId, int $intervalSeconds = 4, int $maxWaitSeconds = 900): ExecutionProjection
    {
        if ($intervalSeconds < 1 || $intervalSeconds > 60 || $maxWaitSeconds < 1 || $maxWaitSeconds > 86400) {
            throw new InvalidArgumentException('Watch interval must be 1..60 seconds and timeout must be 1..86400 seconds.');
        }

        $initial = ($this->observe)($taskId);
        $pending = $initial->attention;
        if ($initial->taskId !== $taskId) {
            throw new RuntimeException('STALE_RUN: owner projected a different Task identity.');
        }
        if ($pending === null || $initial->currentStageId === null) {
            throw new RuntimeException('TRANSITION_REJECTED: watch requires an already-pending, stage-bound Attention.');
        }

        $remaining = $maxWaitSeconds;
        while ($remaining > 0) {
            $delay = min($intervalSeconds, $remaining);
            ($this->pause)($delay);
            $remaining -= $delay;

            $current = ($this->observe)($taskId);
            if ($current->taskId !== $initial->taskId
                || $current->runId !== $initial->runId
                || $current->contractRevision !== $initial->contractRevision
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
                return $current; // Another actor completed this Run: never start a provider.
            }

            // Clearing the flag without an owner-observed attempt/stage transition
            // is not proof that the pending Attention was actually resolved.
            if ($current->currentStageId === $initial->currentStageId
                && $current->currentAttempt === $initial->currentAttempt) {
                throw new RuntimeException('TRANSITION_REJECTED: Attention disappeared without an authorized stage/attempt transition.');
            }

            // Runner acquires its per-task execution lock, re-reads Loop authority,
            // and rejects stale/replaced Attention before invoking any provider.
            return ($this->resume)($taskId);
        }

        throw new RuntimeException('WATCH_TIMEOUT: Attention was not resolved within the configured waiting window.');
    }
}
