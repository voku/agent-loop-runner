<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoopRunner\Runtime\ReconciliationAction;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Runtime\RuntimeReconciler;
use voku\AgentLoopRunner\Runtime\RuntimeStatus;

final class RuntimeReconcilerTest extends TestCase
{
    public function testPersistedResultIsAlwaysResubmittedInsteadOfRerunningHost(): void
    {
        $journal = self::journal(self::result(), RuntimeStatus::RESULT_PERSISTED);
        $projection = self::projection('reviewer', 1, $journal->stageResult?->candidateRevision ?? 'unexpected');

        self::assertSame(
            ReconciliationAction::RESUBMIT_PERSISTED_RESULT,
            (new RuntimeReconciler())->reconcile($projection, $journal),
        );
    }

    public function testSameAuthorizedStageWithoutResultMayContinue(): void
    {
        self::assertSame(
            ReconciliationAction::CONTINUE_AUTHORIZED_ATTEMPT,
            (new RuntimeReconciler())->reconcile(
                self::projection('builder', 1, str_repeat('b', 40)),
                self::journal(null, RuntimeStatus::PREPARED),
            ),
        );
    }

    public function testAdvancedAuthoritativeStageWithoutPersistedResultFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_RUN');

        (new RuntimeReconciler())->reconcile(
            self::projection('reviewer', 1, str_repeat('b', 40)),
            self::journal(null, RuntimeStatus::PROCESS_EXITED),
        );
    }

    public function testContractDriftFailsClosedBeforeAnyResumeDecision(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_CONTRACT');

        $projection = new ExecutionProjection(
            'TASK-1',
            'RUN-1',
            2,
            ExecutionProfileName::SURGICAL,
            'sha256:' . str_repeat('a', 64),
            'builder',
            1,
            null,
            [],
            str_repeat('b', 40),
        );

        (new RuntimeReconciler())->reconcile($projection, self::journal(null, RuntimeStatus::PREPARED));
    }

    public function testCompletedProjectionIsOnlyAcceptedAfterLocalReconciliationMarker(): void
    {
        self::assertSame(
            ReconciliationAction::COMPLETE,
            (new RuntimeReconciler())->reconcile(
                self::projection(null, 0, str_repeat('c', 40)),
                self::journal(null, RuntimeStatus::RECONCILED_ACCEPTED),
            ),
        );
    }

    private static function result(): StageResult
    {
        return new StageResult(
            'submission-1',
            'TASK-1',
            'RUN-1',
            1,
            'sha256:' . str_repeat('a', 64),
            'builder',
            1,
            StageOutcome::COMPLETED,
            'git-worktree-v1:' . str_repeat('b', 40) . ':sha256:' . str_repeat('c', 64),
            [],
            [],
            'done',
        );
    }

    private static function journal(?StageResult $result, RuntimeStatus $status): RuntimeJournal
    {
        return new RuntimeJournal(
            taskId: 'TASK-1',
            runId: 'RUN-1',
            contractRevision: 1,
            executionPlanDigest: 'sha256:' . str_repeat('a', 64),
            stageId: 'builder',
            attempt: 1,
            submissionId: 'submission-1',
            status: $status,
            baseCommit: str_repeat('b', 40),
            candidateRevision: $result?->candidateRevision ?? str_repeat('b', 40),
            stageResult: $result,
        );
    }

    private static function projection(?string $stageId, int $attempt, string $candidateRevision): ExecutionProjection
    {
        return new ExecutionProjection(
            'TASK-1',
            'RUN-1',
            1,
            ExecutionProfileName::SURGICAL,
            'sha256:' . str_repeat('a', 64),
            $stageId,
            $attempt,
            null,
            [],
            $candidateRevision,
        );
    }
}
