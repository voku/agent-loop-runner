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
            static function (string $taskId) use (&$resumeCalls, $resolved): ExecutionProjection {
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
            static function (string $taskId) use (&$called, $resolved): ExecutionProjection {
                self::assertSame('TASK-1', $taskId);
                ++$called;

                return $resolved;
            },
            static function (int $seconds): void {
            },
        );

        self::assertSame($resolved, $watcher->watch('TASK-1', 1, 1));
        self::assertSame(1, $called);
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
        $watcher->watch('TASK-1', 1, 1);
    }

    public function testRefusesAReplacedAttentionWithoutRunning(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $replacement = self::projection('run-1', 1, self::attention('attention-2'));
        $watcher = self::watcher([$waiting, $replacement]);

        $this->expectExceptionMessage('WAITING_FOR_ATTENTION: a different Attention');
        $watcher->watch('TASK-1', 1, 1);
    }

    public function testRefusesRunReplacementWithoutRunning(): void
    {
        $waiting = self::projection('run-1', 1, self::attention());
        $replacement = self::projection('run-2', 2);
        $watcher = self::watcher([$waiting, $replacement]);

        $this->expectExceptionMessage('STALE_RUN:');
        $watcher->watch('TASK-1', 1, 1);
    }

    public function testRefusesDisappearingAttentionWithoutOwnerTransition(): void
    {
        $watcher = self::watcher([
            self::projection('run-1', 1, self::attention()),
            self::projection('run-1', 1),
        ]);

        $this->expectExceptionMessage('TRANSITION_REJECTED: Attention disappeared');
        $watcher->watch('TASK-1', 1, 1);
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

        self::assertSame($complete, $watcher->watch('TASK-1', 1, 1));
    }

    public function testRejectsWhenNothingIsPending(): void
    {
        $watcher = self::watcher([self::projection('run-1', 1)]);

        $this->expectExceptionMessage('TRANSITION_REJECTED: watch requires');
        $watcher->watch('TASK-1', 1, 1);
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
        return new AttentionResumeWatcher(
            static function (string $taskId) use (&$observations): ExecutionProjection {
                self::assertSame('TASK-1', $taskId);

                return array_shift($observations) ?? throw new RuntimeException('Unexpected projection read.');
            },
            static function (string $taskId): ExecutionProjection {
                throw new RuntimeException('Runner must not resume: ' . $taskId);
            },
            static function (int $seconds): void {
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
