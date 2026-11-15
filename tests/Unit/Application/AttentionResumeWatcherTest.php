<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\AttentionKind;
use voku\AgentLoop\Execution\AttentionRequest;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoopRunner\Application\AttentionResumeWatcher;
use voku\AgentLoopRunner\Execution\WaitingForAttention;

final class AttentionResumeWatcherTest extends TestCase
{
    public function testWaitsForOwnerResolutionThenInvokesRunnerResumeOnce(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $resolved = self::projection('run-1', 2);
        $observations = [$waiting, $waiting, $resolved];
        $resumeCalls = 0;
        $delays = [];

        $watcher = new AttentionResumeWatcher(
            static function (string $taskId) use (&$observations): ExecutionProjection {
                self::assertSame('TASK-1', $taskId);

                return array_shift($observations) ?? throw new RuntimeException('Unexpected projection read.');
            },
            static function (string $taskId, ExecutionProjection $accepted) use (&$resumeCalls, $resolved): ExecutionProjection {
                self::assertSame($resolved, $accepted);
                self::assertSame('TASK-1', $taskId);
                ++$resumeCalls;

                return $resolved;
            },
            static function (int $delay) use (&$delays): void {
                $delays[] = $delay;
            },
        );

        self::assertSame($resolved, $watcher->watch('TASK-1', 2, 10));
        self::assertSame(1, $resumeCalls);
        self::assertSame([2, 2], $delays);
    }

    public function testWatchAndResumeContinuesAcrossTwoFreshAttentionBoundaries(): void
    {
        $first = self::projection('run-1', 1, self::attention('attention-1'));
        $firstResolved = self::projection('run-1', 2);
        $secondAttention = new AttentionRequest(
            'attention-2', 'TASK-1', 'run-1', AttentionKind::APPROVAL_REQUIRED,
            'Approval required', 'review', '2026-11-11T12:01:00Z',
        );
        $second = new ExecutionProjection(
            'TASK-1', 'run-1', 1, ExecutionProfileName::SURGICAL,
            'sha256:plan', 'review', 1, $secondAttention, [], 'candidate',
        );
        $secondResolved = new ExecutionProjection(
            'TASK-1', 'run-1', 1, ExecutionProfileName::SURGICAL,
            'sha256:plan', 'verify', 2, null, [], 'candidate',
        );
        $complete = new ExecutionProjection(
            'TASK-1', 'run-1', 1, ExecutionProfileName::SURGICAL,
            'sha256:plan', null, 3, null, [], 'candidate',
        );
        $observations = [$firstResolved, $secondResolved];
        $resumeCalls = 0;
        $watcher = new AttentionResumeWatcher(
            static function (string $taskId) use (&$observations): ExecutionProjection {
                self::assertSame('TASK-1', $taskId);

                return array_shift($observations) ?? throw new RuntimeException('Unexpected owner projection.');
            },
            static function (string $taskId, ExecutionProjection $accepted) use (&$resumeCalls, $firstResolved, $second, $secondResolved, $complete): ExecutionProjection {
                ++$resumeCalls;
                if ($resumeCalls === 1) {
                    self::assertSame($firstResolved, $accepted);
                    throw new WaitingForAttention($second);
                }
                self::assertSame(2, $resumeCalls);
                self::assertSame($secondResolved, $accepted);

                return $complete;
            },
            static function (int $seconds): void {
                self::fail('Resolved owner transitions must be observed without sleeping.');
            },
        );

        self::assertSame($complete, $watcher->watchAndResume('TASK-1', 1, 30, $first));
        self::assertSame(2, $resumeCalls);
        self::assertSame([], $observations);
    }

    public function testWatchAndResumeStopsAtSixteenTotalAttentionBoundaries(): void
    {
        $pending = self::projection('run-1', 1, self::attention('attention-0'));
        $resumeCalls = 0;
        $watcher = new AttentionResumeWatcher(
            static function (string $taskId) use (&$resumeCalls): ExecutionProjection {
                return self::projection('run-1', $resumeCalls + 2);
            },
            static function (string $taskId, ExecutionProjection $accepted) use (&$resumeCalls): ExecutionProjection {
                ++$resumeCalls;
                $attention = new AttentionRequest(
                    'attention-' . $resumeCalls, 'TASK-1', 'run-1', AttentionKind::APPROVAL_REQUIRED,
                    'Approval required', 'build', '2026-11-11T12:00:00Z',
                );

                throw new WaitingForAttention(new ExecutionProjection(
                    'TASK-1', 'run-1', 1, ExecutionProfileName::SURGICAL,
                    'sha256:plan', 'build', $resumeCalls + 1, $attention, [], 'candidate',
                ));
            },
            static function (int $seconds): void {
            },
        );

        try {
            $watcher->watchAndResume('TASK-1', 1, 900, $pending);
            self::fail('Expected the finite Attention boundary limit.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('WATCH_LIMIT:', $exception->getMessage());
        }
        self::assertSame(AttentionResumeWatcher::MAX_ATTENTION_BOUNDARIES - 1, $resumeCalls);
    }

    public function testOneSecondWatchStillObservesOwnerWhenFractionalTimeRemains(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $resolved = self::projection('run-1', 2);
        $clockValues = [0, 600_000_000, 700_000_000, 800_000_000, 900_000_000];
        $observeCalls = 0;
        $resumeCalls = 0;
        $watcher = new AttentionResumeWatcher(
            static function (string $taskId) use (&$observeCalls, $resolved): ExecutionProjection {
                ++$observeCalls;

                return $resolved;
            },
            static function (string $taskId, ExecutionProjection $accepted) use (&$resumeCalls, $resolved): ExecutionProjection {
                self::assertSame($resolved, $accepted);
                ++$resumeCalls;

                return $resolved;
            },
            static function (int $seconds): void {
                self::fail('A just-resolved owner projection must not sleep.');
            },
            static function () use (&$clockValues): int {
                return array_shift($clockValues) ?? throw new RuntimeException('Unexpected clock read.');
            },
        );

        self::assertSame($resolved, $watcher->watchAndResume('TASK-1', 1, 1, $waiting));
        self::assertSame(1, $observeCalls);
        self::assertSame(1, $resumeCalls);
    }

    public function testAllAttentionBoundariesShareOneAbsoluteDeadline(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $resolved = self::projection('run-1', 2);
        $clockValues = [0, 100_000_000, 2_000_000_000, 10_500_000_000];
        $resumeCalls = 0;
        $watcher = new AttentionResumeWatcher(
            static function (string $taskId) use (&$clockValues, $resolved): ExecutionProjection {
                $clockValues = [10_500_000_000];

                return $resolved;
            },
            static function (string $taskId, ExecutionProjection $accepted) use (&$resumeCalls): ExecutionProjection {
                ++$resumeCalls;

                return $accepted;
            },
            static function (int $seconds): void {
            },
            static function () use (&$clockValues): int {
                return array_shift($clockValues) ?? throw new RuntimeException('Unexpected clock read.');
            },
        );

        try {
            $watcher->watchAndResume('TASK-1', 1, 10, $waiting);
            self::fail('An owner resolution after the original deadline must not resume.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('WATCH_TIMEOUT:', $exception->getMessage());
        }

        self::assertSame(0, $resumeCalls);
    }

    public function testReviewChangesMayResumeInDifferentAuthorizedStage(): void
    {
        $attention = new AttentionRequest(
            'review-1', 'TASK-1', 'run-1', AttentionKind::REVIEW_CHANGES_REQUIRED,
            'Changes required', 'review', '2026-11-11T12:00:00Z',
        );
        $waiting = new ExecutionProjection(
            'TASK-1', 'run-1', 1, ExecutionProfileName::SURGICAL,
            'sha256:plan', 'review', 1, $attention, [], 'candidate',
        );
        $resolved = self::projection('run-1', 1);
        $called = 0;
        $observations = [$waiting, $resolved];
        $watcher = new AttentionResumeWatcher(
            static function (string $taskId) use (&$observations): ExecutionProjection {
                return array_shift($observations) ?? throw new RuntimeException('Unexpected projection read: ' . $taskId);
            },
            static function (string $taskId, ExecutionProjection $accepted) use (&$called, $resolved): ExecutionProjection {
                self::assertSame($resolved, $accepted);
                self::assertSame('TASK-1', $taskId);
                ++$called;

                return $resolved;
            },
            static function (int $seconds): void {
            },
        );

        self::assertSame($resolved, $watcher->watch('TASK-1', 1, 2));
        self::assertSame(1, $called);
    }

    public function testResolutionBetweenRunAndWatchStartsResumesWithoutSleeping(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $resolved = self::projection('run-1', 2);
        $calls = 0;
        $watcher = new AttentionResumeWatcher(
            static fn (string $taskId): ExecutionProjection => $resolved,
            static function (string $taskId, ExecutionProjection $accepted) use ($resolved, &$calls): ExecutionProjection {
                self::assertSame($resolved, $accepted);
                ++$calls;

                return $resolved;
            },
            static function (int $seconds): void {
                self::fail('Already resolved Attention must not incur a sleep.');
            },
        );

        self::assertSame($resolved, $watcher->watch('TASK-1', 4, 900, $waiting));
        self::assertSame(1, $calls);
    }

    public function testSlowProjectionReadCannotResumeAfterMonotonicDeadline(): void
    {
        $now = 0;
        $waiting = self::projection('run-1', 1, self::attention());
        $resolved = self::projection('run-1', 2);
        $reads = [$waiting, $resolved];
        $resumeCalls = 0;

        $watcher = new AttentionResumeWatcher(
            static function (string $taskId) use (&$reads, &$now): ExecutionProjection {
                $now += 1_000_000_000;

                return array_shift($reads) ?? throw new RuntimeException('Unexpected owner read.');
            },
            static function (string $taskId, ExecutionProjection $accepted) use (&$resumeCalls): ExecutionProjection {
                ++$resumeCalls;

                return $accepted;
            },
            static function (int $seconds) use (&$now): void {
                $now += $seconds * 1_000_000_000;
            },
            static function () use (&$now): int {
                return $now;
            },
        );

        try {
            $watcher->watch('TASK-1', 1, 3);
            self::fail('Expired owner read cannot authorize a resume.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('WATCH_TIMEOUT:', $exception->getMessage());
        }

        self::assertSame(0, $resumeCalls);
    }

    public function testRefusesCandidateDrift(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $revised = new ExecutionProjection(
            'TASK-1', 'run-1', 1, ExecutionProfileName::SURGICAL,
            'sha256:plan', 'build', 2, null, [], 'different-candidate',
        );
        $watcher = self::watcher([$waiting, $revised]);

        $this->expectExceptionMessage('STALE_RUN:');
        $watcher->watch('TASK-1', 1, 2);
    }

    public function testRefusesAReplacedAttentionWithoutRunning(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $replacement = self::projection('run-1', 1, self::attention('attention-2'));
        $watcher = self::watcher([$waiting, $replacement]);

        $this->expectExceptionMessage('WAITING_FOR_ATTENTION: a different Attention');
        $watcher->watch('TASK-1', 1, 2);
    }

    public function testRefusesRunReplacementWithoutRunning(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $replacement = self::projection('run-2', 2);
        $watcher = self::watcher([$waiting, $replacement]);

        $this->expectExceptionMessage('STALE_RUN:');
        $watcher->watch('TASK-1', 1, 2);
    }

    public function testRefusesDisappearingAttentionWithoutOwnerTransition(): void
    {
        $watcher = self::watcher([
            self::projection('run-1', 1, self::attention()),
            self::projection('run-1', 1),
        ]);

        $this->expectExceptionMessage('TRANSITION_REJECTED: Attention disappeared');
        $watcher->watch('TASK-1', 1, 2);
    }

    public function testTimesOutWithoutAutomaticApproval(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $watcher = self::watcher([$waiting, $waiting, $waiting]);

        $this->expectExceptionMessage('WATCH_TIMEOUT:');
        $watcher->watch('TASK-1', 2, 3);
    }

    public function testDoesNotRunIfAlreadyCompletedElsewhere(): void
    {
        $complete = new ExecutionProjection(
            'TASK-1', 'run-1', 1, ExecutionProfileName::SURGICAL, 'sha256:plan',
            null, 2, null, [], 'candidate',
        );
        $watcher = self::watcher([self::projection('run-1', 1, self::attention()), $complete]);

        self::assertSame($complete, $watcher->watch('TASK-1', 1, 2));
    }

    public function testRejectsWhenNothingIsPending(): void
    {
        $watcher = self::watcher([self::projection('run-1', 1)]);

        $this->expectExceptionMessage('TRANSITION_REJECTED: watch requires');
        $watcher->watch('TASK-1', 1, 2);
    }

    public function testRejectsInvalidPollingBounds(): void
    {
        $watcher = self::watcher([]);
        $this->expectException(\InvalidArgumentException::class);
        $watcher->watch('TASK-1', 0, 1);
    }

    /**
     * @param list<ExecutionProjection> $observations
     */
    private static function watcher(array $observations): AttentionResumeWatcher
    {
        $now = 0;

        return new AttentionResumeWatcher(
            static function (string $taskId) use (&$observations): ExecutionProjection {
                self::assertSame('TASK-1', $taskId);

                return array_shift($observations) ?? throw new RuntimeException('Unexpected projection read.');
            },
            static function (string $taskId, ExecutionProjection $accepted): ExecutionProjection {
                throw new RuntimeException('Runner must not resume: ' . $taskId);
            },
            static function (int $seconds) use (&$now): void {
                $now += $seconds * 1_000_000_000;
            },
            static function () use (&$now): int {
                return $now;
            },
        );
    }

    private static function attention(string $id = 'attention-1'): AttentionRequest
    {
        return new AttentionRequest(
            $id, 'TASK-1', 'run-1', AttentionKind::APPROVAL_REQUIRED,
            'Approval required', 'build', '2026-11-11T12:00:00Z',
        );
    }

    private static function projection(string $runId, int $attempt, ?AttentionRequest $attention = null): ExecutionProjection
    {
        return new ExecutionProjection(
            'TASK-1', $runId, 1, ExecutionProfileName::SURGICAL,
            'sha256:plan', 'build', $attempt, $attention, [], 'candidate',
        );
    }
}
