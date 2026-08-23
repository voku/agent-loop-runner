<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use RuntimeException;
use voku\AgentLoop\Execution\ExecutionProjection;

final readonly class RuntimeReconciler
{
    public function reconcile(ExecutionProjection $projection, RuntimeJournal $journal): ReconciliationAction
    {
        $this->assertIdentity($projection, $journal);

        if ($journal->stageResult !== null) {
            return ReconciliationAction::RESUBMIT_PERSISTED_RESULT;
        }

        if ($projection->attention !== null) {
            return ReconciliationAction::WAITING_FOR_ATTENTION;
        }

        if ($projection->complete()) {
            if ($journal->status === RuntimeStatus::RECONCILED_ACCEPTED) {
                return ReconciliationAction::COMPLETE;
            }

            throw new RuntimeException('STALE_RUN: authoritative execution completed without a persisted Runner StageResult.');
        }

        if ($projection->currentStageId !== $journal->stageId
            || $projection->currentAttempt !== $journal->attempt) {
            throw new RuntimeException('STALE_RUN: Runner journal does not match the authoritative current stage/attempt.');
        }

        return ReconciliationAction::CONTINUE_AUTHORIZED_ATTEMPT;
    }

    private function assertIdentity(ExecutionProjection $projection, RuntimeJournal $journal): void
    {
        if ($projection->taskId !== $journal->taskId) {
            throw new RuntimeException('STALE_RUN: Runner task id does not match authoritative execution.');
        }
        if ($projection->runId !== $journal->runId) {
            throw new RuntimeException('STALE_RUN: Runner Run id does not match authoritative execution.');
        }
        if ($projection->contractRevision !== $journal->contractRevision) {
            throw new RuntimeException('STALE_CONTRACT: Runner Contract revision does not match authoritative execution.');
        }
        if (!hash_equals($projection->executionPlanDigest, $journal->executionPlanDigest)) {
            throw new RuntimeException('STALE_RUN: Runner execution-plan digest does not match authoritative execution.');
        }
    }
}
