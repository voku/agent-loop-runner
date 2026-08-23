<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use RuntimeException;
use Throwable;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionOwner;
use voku\AgentLoopRunner\Host\HostAdapterFactory;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Process\EnvironmentProjector;
use voku\AgentLoopRunner\Process\OwnedProcessCanceller;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
use voku\AgentLoopRunner\Runtime\DiagnosticLogStore;
use voku\AgentLoopRunner\Runtime\ReconciliationAction;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Runtime\RuntimeJournalStore;
use voku\AgentLoopRunner\Runtime\RuntimeProcessObserver;
use voku\AgentLoopRunner\Runtime\RuntimeReconciler;
use voku\AgentLoopRunner\Runtime\RuntimeStatus;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceLease;

final readonly class ExecutionCoordinator
{
    private const int MAX_AUTHORIZED_STEPS = 32;

    public function __construct(
        private string $projectRoot,
        private ExecutionOwner $owner,
        private RunnerConfig $config,
        private HostAdapterFactory $hostAdapters,
        private ProcessSupervisor $processes,
        private EnvironmentProjector $environment,
        private RunWorkspaceManager $workspaces,
        private RuntimeJournalStore $journals,
        private RuntimeReconciler $reconciler,
        private CompletionEnvelopeParser $completionParser,
        private DiagnosticLogStore $logs,
        private OwnedProcessCanceller $processCanceller,
    ) {
    }

    public function run(string $taskId): ExecutionProjection
    {
        for ($step = 0; $step < self::MAX_AUTHORIZED_STEPS; ++$step) {
            $projection = $this->owner->projection($taskId);
            $journal = $this->journals->load($taskId);

            if ($journal !== null) {
                $action = $this->reconciler->reconcile($projection, $journal);
                if ($action === ReconciliationAction::RESUBMIT_PERSISTED_RESULT) {
                    $projection = $this->resubmitPersistedResult($journal);
                    if ($projection->attention !== null || $projection->complete()) {
                        return $projection;
                    }
                    $this->journals->delete($taskId);
                    continue;
                }
                if ($action === ReconciliationAction::WAITING_FOR_ATTENTION) {
                    if ($journal->status !== RuntimeStatus::WAITING_FOR_ATTENTION) {
                        $this->journals->save($journal->withStatus(RuntimeStatus::WAITING_FOR_ATTENTION));
                    }

                    return $projection;
                }
                if ($action === ReconciliationAction::COMPLETE) {
                    return $projection;
                }
            }

            if ($projection->attention !== null || $projection->complete()) {
                return $projection;
            }
            $stageId = $projection->currentStageId;
            if ($stageId === null) {
                return $projection;
            }

            $bundle = $this->owner->prepareStage($taskId, $stageId);
            if ($bundle->kind === ExecutionStageKind::DETERMINISTIC) {
                if ($journal !== null) {
                    throw new RuntimeException('STALE_RUN: Runner journal exists for a deterministic owner stage.');
                }
                $projection = $this->owner->runDeterministicStage($taskId, $stageId);
                if ($projection->attention !== null || $projection->complete()) {
                    return $projection;
                }
                continue;
            }
            if ($bundle->kind !== ExecutionStageKind::AGENT || $bundle->roleId === null || trim($bundle->roleId) === '') {
                throw new RuntimeException('TRANSITION_REJECTED: Runner received an unsupported execution stage.');
            }

            if ($journal === null) {
                $journal = RuntimeJournal::prepared($bundle, $this->submissionId($bundle));
                $this->journals->save($journal);
            }

            $projection = $this->continueAgentAttempt($bundle, $journal);
            if ($projection->attention !== null || $projection->complete()) {
                return $projection;
            }
            $this->journals->delete($taskId);
        }

        throw new RuntimeException('TRANSITION_REJECTED: Runner exceeded the bounded authorized stage limit.');
    }

    private function continueAgentAttempt(StageExecutionBundle $bundle, RuntimeJournal $journal): ExecutionProjection
    {
        return match ($journal->status) {
            RuntimeStatus::PREPARED => $this->executePrepared($bundle, $journal),
            RuntimeStatus::PROCESS_STARTED => $this->recoverInterruptedProcess($bundle, $journal),
            RuntimeStatus::PROCESS_EXITED => $this->completeExitedProcess($bundle, $journal, $this->workspaces->acquire($bundle)),
            RuntimeStatus::CANCELLED => $this->persistFailureAndSubmit(
                $bundle,
                $journal,
                $this->workspaces->acquire($bundle),
                'PROCESS_FAILED: Runner-owned process was cancelled.',
            ),
            RuntimeStatus::FAILED => $this->persistFailureAndSubmit(
                $bundle,
                $journal,
                $this->workspaces->acquire($bundle),
                'PROCESS_FAILED: Runner attempt failed before a StageResult was persisted.',
            ),
            RuntimeStatus::WAITING_FOR_ATTENTION => throw new RuntimeException(
                'STALE_RUN: Runner observed Attention but agent-loop no longer projects that Attention.',
            ),
            RuntimeStatus::RESULT_PERSISTED,
            RuntimeStatus::SUBMISSION_ATTEMPTED,
            RuntimeStatus::RECONCILED_ACCEPTED => throw new RuntimeException(
                'STALE_RUN: persisted-result state is missing its StageResult.',
            ),
        };
    }

    private function executePrepared(StageExecutionBundle $bundle, RuntimeJournal $journal): ExecutionProjection
    {
        $roleId = $bundle->roleId;
        if ($roleId === null || trim($roleId) === '') {
            throw new RuntimeException('TRANSITION_REJECTED: agent stage has no role id.');
        }
        $hostId = $this->config->hostForRole($roleId);
        $host = $this->hostAdapters->create($hostId, $this->config);
        $environment = $this->environment->project($this->config->environmentAllowlist);
        $availability = $host->probe($this->processes, $this->projectRoot, $environment);
        if (!$availability->available()) {
            return $this->persistFailureAndSubmit(
                $bundle,
                $journal,
                null,
                'HOST_UNAVAILABLE: ' . ($availability->failure ?? ('host ' . $hostId . ' is unavailable')),
            );
        }

        $lease = $this->workspaces->acquire($bundle);
        $journal = $journal->withProcessStarting($hostId, $availability->version);
        $this->journals->save($journal);
        $observer = new RuntimeProcessObserver(
            $this->journals,
            $bundle->taskId,
            $journal->submissionId,
            $hostId,
            $availability->version,
        );

        try {
            $execution = $host->execute(new HostExecutionRequest(
                $roleId,
                $lease->path,
                $bundle->prompt,
                $environment,
                $this->config->timeoutSeconds,
                $observer,
            ), $this->processes);
        } catch (Throwable $exception) {
            $current = $this->journals->load($bundle->taskId);
            if ($current !== null && $current->submissionId === $journal->submissionId && $current->stageResult !== null) {
                return $this->resubmitPersistedResult($current);
            }

            return $this->persistFailureAndSubmit(
                $bundle,
                $current ?? $journal,
                $lease,
                'PROCESS_FAILED: ' . $exception->getMessage(),
            );
        }

        $current = $this->journals->load($bundle->taskId);
        if ($current === null || $current->submissionId !== $journal->submissionId) {
            throw new RuntimeException('STALE_RUN: Runner journal changed while the owned host process was executing.');
        }
        if ($current->stageResult !== null) {
            return $this->resubmitPersistedResult($current);
        }
        if ($current->status === RuntimeStatus::CANCELLED) {
            return $this->persistFailureAndSubmit(
                $bundle,
                $current,
                $lease,
                'PROCESS_FAILED: Runner-owned process was cancelled.',
            );
        }
        if ($current->status !== RuntimeStatus::PROCESS_STARTED) {
            throw new RuntimeException('STALE_RUN: Runner journal changed unexpectedly during host execution.');
        }

        $references = $this->logs->persist(
            $bundle->taskId,
            $bundle->runId,
            $bundle->stageId,
            $bundle->attempt,
            $execution->process->stdout,
            $execution->process->stderr,
        );
        $current = $current->withProcessExited($execution->process, $references['stdout'], $references['stderr']);
        $this->journals->save($current);

        return $this->completeExitedProcess($bundle, $current, $lease);
    }

    private function recoverInterruptedProcess(StageExecutionBundle $bundle, RuntimeJournal $journal): ExecutionProjection
    {
        if ($journal->processPid !== null && $this->processCanceller->alive($journal->processPid)) {
            throw new RuntimeException(
                'PROCESS_FAILED: Runner-owned process ' . $journal->processPid . ' is still active; refuse duplicate execution.',
            );
        }

        return $this->persistFailureAndSubmit(
            $bundle,
            $journal,
            $this->workspaces->acquire($bundle),
            $journal->processPid === null
                ? 'PROCESS_FAILED: process start outcome is uncertain after restart; refusing duplicate execution.'
                : 'PROCESS_FAILED: owned process ended before its result was persisted; refusing duplicate execution.',
        );
    }

    private function completeExitedProcess(
        StageExecutionBundle $bundle,
        RuntimeJournal $journal,
        WorkspaceLease $lease,
    ): ExecutionProjection {
        if ($journal->stdoutLog === null || $journal->stderrLog === null || $journal->exitCode === null) {
            return $this->persistFailureAndSubmit(
                $bundle,
                $journal,
                $lease,
                'PROCESS_FAILED: exited process observation is incomplete.',
            );
        }

        try {
            $candidateRevision = $this->workspaces->candidateRevisionAfter($bundle, $lease);
        } catch (RuntimeException $exception) {
            return $this->persistFailureAndSubmit(
                $bundle,
                $journal,
                $lease,
                $exception->getMessage(),
                $bundle->candidateRevision,
            );
        }

        if ($journal->timedOut) {
            return $this->persistFailureAndSubmit(
                $bundle,
                $journal,
                $lease,
                'PROCESS_TIMEOUT: host process exceeded the configured timeout.',
                $candidateRevision,
            );
        }
        if ($journal->exitCode !== 0) {
            return $this->persistFailureAndSubmit(
                $bundle,
                $journal,
                $lease,
                'PROCESS_FAILED: host process exited with code ' . $journal->exitCode . '.',
                $candidateRevision,
            );
        }

        try {
            $completion = $this->completionParser->parse($bundle, $this->logs->read($journal->stdoutLog));
        } catch (RuntimeException $exception) {
            return $this->persistFailureAndSubmit(
                $bundle,
                $journal,
                $lease,
                $exception->getMessage(),
                $candidateRevision,
            );
        }

        $result = new StageResult(
            $journal->submissionId,
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $completion->outcome,
            $candidateRevision,
            $completion->artifactReferences,
            $completion->validationReferences,
            $completion->summary,
        );

        $journal = $journal->withStageResult($result, $candidateRevision);
        $this->journals->save($journal);

        return $this->resubmitPersistedResult($journal);
    }

    private function persistFailureAndSubmit(
        StageExecutionBundle $bundle,
        RuntimeJournal $journal,
        ?WorkspaceLease $lease,
        string $summary,
        ?string $candidateRevision = null,
    ): ExecutionProjection {
        if ($journal->stageResult !== null) {
            return $this->resubmitPersistedResult($journal);
        }

        $candidateRevision ??= $this->failureCandidateRevision($bundle, $lease);
        $artifacts = [];
        foreach ([$journal->stdoutLog, $journal->stderrLog] as $reference) {
            if ($reference !== null) {
                $artifacts[] = $reference;
            }
        }
        $result = new StageResult(
            $journal->submissionId,
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::FAILED,
            $candidateRevision,
            $artifacts,
            [],
            $this->boundedSummary($summary),
        );
        $journal = $journal->withStageResult($result, $candidateRevision);
        $this->journals->save($journal);

        return $this->resubmitPersistedResult($journal);
    }

    private function failureCandidateRevision(StageExecutionBundle $bundle, ?WorkspaceLease $lease): string
    {
        if ($lease === null) {
            return $bundle->candidateRevision;
        }
        try {
            return $this->workspaces->candidateRevisionAfter($bundle, $lease);
        } catch (RuntimeException) {
            return $bundle->candidateRevision;
        }
    }

    private function resubmitPersistedResult(RuntimeJournal $journal): ExecutionProjection
    {
        $result = $journal->stageResult;
        if ($result === null) {
            throw new RuntimeException('STALE_RUN: resubmission requires an exact persisted StageResult.');
        }

        $this->workspaces->releaseAttempt(
            $journal->taskId,
            $journal->runId,
            $journal->baseCommit,
            $journal->stageId,
            $journal->attempt,
        );
        $journal = $journal->withStatus(RuntimeStatus::SUBMISSION_ATTEMPTED);
        $this->journals->save($journal);
        $projection = $this->owner->submitStageResult($result);
        $this->journals->save($journal->withStatus(RuntimeStatus::RECONCILED_ACCEPTED));

        return $projection;
    }

    private function submissionId(StageExecutionBundle $bundle): string
    {
        return 'runner:sha256:' . hash('sha256', implode("\0", [
            $bundle->taskId,
            $bundle->runId,
            (string) $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            (string) $bundle->attempt,
            bin2hex(random_bytes(16)),
        ]));
    }

    private function boundedSummary(string $summary): string
    {
        $summary = trim($summary);
        if ($summary === '') {
            return 'PROCESS_FAILED: Runner stage failed without a diagnostic summary.';
        }

        return strlen($summary) <= 4096 ? $summary : substr($summary, 0, 4096);
    }
}
