<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use RuntimeException;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoopRunner\Execution\ExecutionOwner;
use voku\AgentLoopRunner\Process\OwnedProcessCanceller;
use voku\AgentLoopRunner\Runtime\ReconciliationAction;
use voku\AgentLoopRunner\Runtime\RuntimeJournalStore;
use voku\AgentLoopRunner\Runtime\RuntimeReconciler;
use voku\AgentLoopRunner\Runtime\RuntimeStatus;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;

final readonly class RunnerControl
{
    public function __construct(
        private ExecutionOwner $owner,
        private ExecutionCoordinator $coordinator,
        private RuntimeJournalStore $journals,
        private RuntimeReconciler $reconciler,
        private OwnedProcessCanceller $processCanceller,
        private RunWorkspaceManager $workspaces,
    ) {
    }

    public function cancel(string $taskId): ExecutionProjection
    {
        $projection = $this->owner->projection($taskId);
        $journal = $this->journals->load($taskId);
        if ($journal === null) {
            if ($projection->attention !== null || $projection->complete()) {
                return $projection;
            }
            throw new RuntimeException('PROCESS_FAILED: no Runner-owned attempt exists to cancel.');
        }

        $action = $this->reconciler->reconcile($projection, $journal);
        if ($action === ReconciliationAction::RESUBMIT_PERSISTED_RESULT) {
            return $this->coordinator->run($taskId);
        }
        if ($action === ReconciliationAction::WAITING_FOR_ATTENTION || $action === ReconciliationAction::COMPLETE) {
            return $projection;
        }
        if ($journal->status === RuntimeStatus::PROCESS_EXITED) {
            return $this->coordinator->run($taskId);
        }
        if (!in_array($journal->status, [RuntimeStatus::PREPARED, RuntimeStatus::PROCESS_STARTED], true)) {
            throw new RuntimeException('PROCESS_FAILED: Runner attempt is not cancellable from state ' . $journal->status->value . '.');
        }

        $cancelled = $journal->withStatus(RuntimeStatus::CANCELLED);
        $this->journals->transition($journal, $cancelled);
        if ($journal->processPid !== null) {
            $this->processCanceller->cancel($journal->processPid);
        }

        return $this->coordinator->run($taskId);
    }

    public function cleanup(string $taskId): void
    {
        $projection = $this->owner->projection($taskId);
        if ($projection->attention !== null) {
            throw new RuntimeException('WAITING_FOR_ATTENTION: cleanup is blocked while authoritative Attention is pending.');
        }
        if (!$projection->complete()) {
            throw new RuntimeException('TRANSITION_REJECTED: cleanup requires a completed governed execution.');
        }

        $journal = $this->journals->load($taskId);
        if ($journal !== null) {
            $this->reconciler->reconcile($projection, $journal);
            if ($journal->status !== RuntimeStatus::RECONCILED_ACCEPTED || $journal->stageResult === null) {
                throw new RuntimeException('TRANSITION_REJECTED: cleanup requires a reconciled accepted Runner attempt.');
            }
            if ($journal->processPid !== null && $this->processCanceller->alive($journal->processPid)) {
                throw new RuntimeException('PROCESS_FAILED: cleanup refuses an active Runner-owned process.');
            }
            $this->workspaces->releaseAttempt(
                $journal->taskId,
                $journal->runId,
                $journal->baseCommit,
                $journal->stageId,
                $journal->attempt,
            );
        }

        $this->workspaces->cleanup($taskId, $projection->runId);
        if ($journal !== null) {
            $this->journals->deleteIf($journal);
        }
    }
}
