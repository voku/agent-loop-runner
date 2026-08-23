<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use InvalidArgumentException;
use voku\AgentLoop\Execution\StageResult;

final readonly class RuntimeJournal
{
    public string $taskId;
    public string $runId;
    public string $executionPlanDigest;
    public string $stageId;
    public string $submissionId;
    public string $baseCommit;
    public string $candidateRevision;

    public function __construct(
        string $taskId,
        string $runId,
        public int $contractRevision,
        string $executionPlanDigest,
        string $stageId,
        public int $attempt,
        string $submissionId,
        public RuntimeStatus $status,
        string $baseCommit,
        string $candidateRevision,
        public ?string $hostId = null,
        public ?string $hostVersion = null,
        public ?int $processPid = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
        public ?int $exitCode = null,
        public bool $timedOut = false,
        public ?string $stdoutLog = null,
        public ?string $stderrLog = null,
        public ?StageResult $stageResult = null,
    ) {
        $this->taskId = self::nonEmpty($taskId, 'task id');
        $this->runId = self::nonEmpty($runId, 'Run id');
        $this->executionPlanDigest = self::nonEmpty($executionPlanDigest, 'execution-plan digest');
        $this->stageId = self::nonEmpty($stageId, 'stage id');
        $this->submissionId = self::nonEmpty($submissionId, 'submission id');
        $this->baseCommit = self::nonEmpty($baseCommit, 'base commit');
        $this->candidateRevision = self::nonEmpty($candidateRevision, 'candidate revision');

        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Runtime journal requires positive Contract revision and attempt.');
        }
        if ($this->processPid !== null && $this->processPid < 1) {
            throw new InvalidArgumentException('Runtime journal process PID must be positive when present.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'stage_id' => $this->stageId,
            'attempt' => $this->attempt,
            'submission_id' => $this->submissionId,
            'status' => $this->status->value,
            'base_commit' => $this->baseCommit,
            'candidate_revision' => $this->candidateRevision,
            'host_id' => $this->hostId,
            'host_version' => $this->hostVersion,
            'process_pid' => $this->processPid,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'exit_code' => $this->exitCode,
            'timed_out' => $this->timedOut,
            'stdout_log' => $this->stdoutLog,
            'stderr_log' => $this->stderrLog,
            'stage_result' => $this->stageResult?->toArray(),
        ];
    }

    private static function nonEmpty(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Runtime journal requires non-empty ' . $field . '.');
        }

        return $value;
    }
}
