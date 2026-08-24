<?php

declare(strict_types=1);
namespace voku\AgentLoopRunner\Execution;

use DateTimeImmutable;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionEnvironmentObservation;
use voku\AgentLoop\Execution\ExecutionEnvironmentTool;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Diagnostics\DiagnosticLogStore;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Process\EnvironmentProjector;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\JournalProcessObserver;
use voku\AgentLoopRunner\Runtime\RunExecutionLock;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;

final readonly class ExecutionCoordinator
{
    /** @param array<string, HostAdapter> $hosts */
    public function __construct(
        private ExecutionGatewayPort $gateway,
        private RuntimeJournal $journal,
        private RunWorkspaceManager $workspaces,
        private CompletionEnvelopeParser $parser,
        private RunnerConfig $config,
        private array $hosts,
        private ProcessSupervisor $supervisor,
        private DiagnosticLogStore $logs,
        private CoordinatorHook $hook = new NullCoordinatorHook(),
        private int $iterationLimit = 64,
    ) {}

    public function run(string $taskId): ExecutionProjection
    {
        return $this->withExecutionLock($taskId);
    }

    public function resume(string $taskId): ExecutionProjection
    {
        return $this->withExecutionLock($taskId);
    }

    private function withExecutionLock(string $taskId): ExecutionProjection
    {
        $lock = RunExecutionLock::acquire(new RunnerLayout($this->workspaces->projectRoot()), $taskId);
        try {
            return $this->reconcile($taskId);
        } finally {
            $lock->release();
        }
    }

    private function reconcile(string $taskId): ExecutionProjection
    {
        for ($iteration = 0; $iteration < $this->iterationLimit; ++$iteration) {
            $projection = $this->gateway->projection($taskId);
            $local = $this->journal->load($taskId);
            if ($local !== null && $local->taskId !== $projection->taskId) throw new RuntimeException('STALE_RUN: runtime task identity conflicts with authoritative projection.');
            if ($projection->attention !== null) throw new RuntimeException('WAITING_FOR_ATTENTION: ' . $projection->attention->id);
            if ($projection->complete()) return $projection;
            $stageId = $projection->currentStageId;
            if ($stageId === null) return $projection;

            if ($local !== null) {
                $samePlan = $local->runId === $projection->runId && $local->contractRevision === $projection->contractRevision && $local->executionPlanDigest === $projection->executionPlanDigest;
                if (!$samePlan) throw new RuntimeException('STALE_RUN: runtime identity conflicts with authoritative projection.');
                if ($local->stageId !== $stageId || $local->attempt !== $projection->currentAttempt) {
                    $this->journal->save($this->copy($local, AttemptStatus::ReconciledAccepted));
                    $local = null;
                }
                if ($local !== null && $local->stageResult !== null) {
                    $this->gateway->submitStageResult($this->restoreResult($local->stageResult));
                    $this->journal->save($this->copy($local, AttemptStatus::SubmissionAttempted));
                    continue;
                }
                if ($local !== null && $local->status !== AttemptStatus::Prepared) {
                    throw new RuntimeException('PROCESS_FAILED: incomplete prior process observation requires operator evidence.');
                }
            }

            $bundle = $this->gateway->prepareStage($taskId, $stageId);
            if ($bundle->kind === ExecutionStageKind::DETERMINISTIC) {
                $this->gateway->runDeterministicStage($taskId, $stageId);
                continue;
            }
            if ($bundle->baseCommit === null || $bundle->roleId === null) throw new RuntimeException('TRANSITION_REJECTED: agent stage bundle is incomplete.');
            $configuredRoot = realpath($this->workspacesRoot());
            $bundleRoot = realpath($bundle->repositoryRoot);
            if (!is_string($configuredRoot) || !is_string($bundleRoot) || $configuredRoot !== $bundleRoot) throw new RuntimeException('STALE_WORKSPACE: bundle repository root conflicts with configured project root.');
            $workspace = $this->workspaces->acquire($bundle->taskId, $bundle->runId, $bundle->baseCommit, $bundle->stageId, $bundle->attempt, $bundle->mayMutate, $bundle->candidateRevision);
            $hostId = $this->config->hostForRole($bundle->roleId);
            $host = $this->hosts[$hostId] ?? null;
            if (!$host instanceof HostAdapter) throw new RuntimeException('HOST_UNAVAILABLE: ' . $hostId);

            $environment = (new EnvironmentProjector())->project($this->config->environmentAllowlist);
            $availability = $host->probe($this->supervisor, $workspace->lease->path, $environment);
            if ($host->id() !== $hostId || $availability->hostId !== $hostId) {
                throw new RuntimeException('HOST_MISMATCH: configured host identity conflicts with adapter observation.');
            }
            if (!$availability->available()) {
                throw new RuntimeException('HOST_UNAVAILABLE: ' . $hostId);
            }

            $bundle = $this->gateway->prepareStageForEnvironment(
                $taskId,
                $stageId,
                new ExecutionEnvironmentObservation(
                    $bundle->taskId,
                    $bundle->runId,
                    $bundle->contractRevision,
                    $bundle->executionPlanDigest,
                    $bundle->stageId,
                    $bundle->attempt,
                    $bundle->candidateRevision,
                    $availability->hostId,
                    [new ExecutionEnvironmentTool($availability->hostId, true, $availability->version)],
                ),
            );
            if ($bundle->environmentObservationDigest === null) {
                throw new RuntimeException('TRANSITION_REJECTED: environment-aware stage bundle is missing observation lineage.');
            }

            $submissionId = $local !== null ? $local->submissionId : $this->submissionId($bundle->taskId, $bundle->runId, $bundle->stageId, $bundle->attempt);
            $attempt = new RuntimeAttempt($bundle->taskId, $bundle->runId, $bundle->contractRevision, $bundle->executionPlanDigest, $bundle->stageId, $bundle->attempt, $hostId, hash('sha256', $workspace->lease->path), $submissionId);
            $this->journal->save($attempt);
            try {
                $this->hook->reached('before_process_start');
                $result = $host->execute(new HostExecutionRequest($bundle->roleId, $workspace->lease->path, $bundle->prompt, $environment, $this->config->timeoutSeconds, new JournalProcessObserver($this->journal, $attempt)), $this->supervisor);
                $this->hook->reached('after_process_exit');
                if ($result->process->startedAt === '' || $result->process->finishedAt === '') throw new RuntimeException('PROCESS_FAILED: process evidence is incomplete.');
                $logEvidence = $this->logs->persist($attempt->taskId, $attempt->runId, $attempt->stageId, $attempt->attempt, $result->process->stdout, $result->process->stderr);
                $exitedAttempt = new RuntimeAttempt($attempt->taskId, $attempt->runId, $attempt->contractRevision, $attempt->executionPlanDigest, $attempt->stageId, $attempt->attempt, $attempt->hostId, $attempt->workspaceIdentity, $attempt->submissionId, AttemptStatus::ProcessExited, null, null, array_merge(['started_at'=>$result->process->startedAt,'exited_at'=>$result->process->finishedAt,'exit_code'=>$result->process->exitCode,'timed_out'=>$result->process->timedOut],$logEvidence));
                $this->journal->save($exitedAttempt);
                if ($result->process->timedOut) throw new RuntimeException('PROCESS_TIMEOUT: host process timed out.');
                if (!$result->process->successful()) throw new RuntimeException('PROCESS_FAILED: host exited ' . $result->process->exitCode . '.');
                $accepted = array_map(static fn (StageOutcome $outcome): string => $outcome->value, $bundle->acceptedOutcomes);
                $marker = rtrim($bundle->completionMarker);
                if ($accepted === [] || $marker === '') throw new RuntimeException('TRANSITION_REJECTED: bundle completion protocol is empty.');
                $envelope = $this->parser->parse($result->process->stdout, $accepted, $marker);
                $candidate = $this->workspaces->candidateAfter($workspace);
                $stageResult = new StageResult($submissionId, $bundle->taskId, $bundle->runId, $bundle->contractRevision, $bundle->executionPlanDigest, $bundle->stageId, $bundle->attempt, StageOutcome::from($envelope->outcome), $candidate, $envelope->artifactReferences, $envelope->validationReferences, $envelope->summary);
                if ($candidate === '') throw new RuntimeException('PROCESS_FAILED: process evidence is incomplete.');
                $persisted = new RuntimeAttempt($attempt->taskId, $attempt->runId, $attempt->contractRevision, $attempt->executionPlanDigest, $attempt->stageId, $attempt->attempt, $attempt->hostId, $attempt->workspaceIdentity, $attempt->submissionId, AttemptStatus::ResultPersisted, $candidate, $stageResult->toArray(), $exitedAttempt->process);
                $this->journal->save($persisted);
                $this->hook->reached('after_result_persisted');
                $this->gateway->submitStageResult($stageResult);
                $this->hook->reached('after_submission_accepted');
                $this->journal->save($this->copy($persisted, AttemptStatus::ReconciledAccepted));
            } finally {
                $workspace->mutationLock?->release();
            }
        }
        throw new RuntimeException('TRANSITION_REJECTED: execution iteration limit reached.');
    }

    private function workspacesRoot(): string { return $this->workspaces->projectRoot(); }

    private function submissionId(string $task, string $run, string $stage, int $attempt): string
    { return 'runner-' . hash('sha256', implode("\0", [$task, $run, $stage, (string) $attempt, bin2hex(random_bytes(16))])); }

    private function copy(RuntimeAttempt $a, AttemptStatus $status): RuntimeAttempt
    { return new RuntimeAttempt($a->taskId, $a->runId, $a->contractRevision, $a->executionPlanDigest, $a->stageId, $a->attempt, $a->hostId, $a->workspaceIdentity, $a->submissionId, $status, $a->candidateRevision, $a->stageResult, $a->process, (new DateTimeImmutable())->format(DATE_ATOM)); }

    /** @param array<string, mixed> $data */
    private function restoreResult(array $data): StageResult
    {
        foreach (['submission_id','task_id','run_id','contract_revision','execution_plan_digest','stage_id','attempt','outcome','candidate_revision','artifact_references','validation_references','summary'] as $key) if (!array_key_exists($key, $data)) throw new RuntimeException('INVALID_STAGE_RESULT: persisted result is incomplete.');
        if (!is_string($data['submission_id']) || !is_string($data['task_id']) || !is_string($data['run_id']) || !is_int($data['contract_revision']) || !is_string($data['execution_plan_digest']) || !is_string($data['stage_id']) || !is_int($data['attempt']) || !is_string($data['outcome']) || !is_string($data['candidate_revision']) || !is_array($data['artifact_references']) || !is_array($data['validation_references']) || !is_string($data['summary'])) throw new RuntimeException('INVALID_STAGE_RESULT: persisted result types are invalid.');
        $artifacts = $this->strings($data['artifact_references']); $validation = $this->strings($data['validation_references']);
        return new StageResult($data['submission_id'], $data['task_id'], $data['run_id'], $data['contract_revision'], $data['execution_plan_digest'], $data['stage_id'], $data['attempt'], StageOutcome::from($data['outcome']), $data['candidate_revision'], $artifacts, $validation, $data['summary']);
    }
    /**
     * @param array<mixed> $values
     * @return list<non-empty-string>
     */
    private function strings(array $values): array { $result=[]; foreach ($values as $value) { if (!is_string($value) || $value==='') throw new RuntimeException('INVALID_STAGE_RESULT: persisted references are invalid.'); $result[]=$value; } return $result; }
}
