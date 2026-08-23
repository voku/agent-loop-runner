<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoopRunner\Process\ProcessResult;

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
        if ($this->stageResult !== null && $this->stageResult->submissionId !== $this->submissionId) {
            throw new InvalidArgumentException('Runtime journal StageResult must use the stable attempt submission id.');
        }
    }

    public static function prepared(StageExecutionBundle $bundle, string $submissionId): self
    {
        if ($bundle->baseCommit === null) {
            throw new RuntimeException('STALE_WORKSPACE: Runner execution requires an exact governed base commit.');
        }

        return new self(
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $submissionId,
            RuntimeStatus::PREPARED,
            $bundle->baseCommit,
            $bundle->candidateRevision,
        );
    }

    public function withProcessStarting(string $hostId, ?string $hostVersion): self
    {
        if ($this->status !== RuntimeStatus::PREPARED) {
            throw new RuntimeException('STALE_RUN: process start can be armed only from a prepared Runner attempt.');
        }

        return $this->copy(
            status: RuntimeStatus::PROCESS_STARTED,
            hostId: self::nonEmpty($hostId, 'host id'),
            hostVersion: $hostVersion,
        );
    }

    public function withProcessStarted(int $pid, string $startedAt, string $hostId, ?string $hostVersion): self
    {
        if ($this->status !== RuntimeStatus::PROCESS_STARTED || $this->processPid !== null) {
            throw new RuntimeException('STALE_RUN: owned PID can be attached only to an armed process start.');
        }

        return $this->copy(
            status: RuntimeStatus::PROCESS_STARTED,
            hostId: self::nonEmpty($hostId, 'host id'),
            hostVersion: $hostVersion,
            processPid: $pid,
            startedAt: self::nonEmpty($startedAt, 'process start timestamp'),
        );
    }

    public function withProcessExited(ProcessResult $result, string $stdoutLog, string $stderrLog): self
    {
        if ($this->status !== RuntimeStatus::PROCESS_STARTED) {
            throw new RuntimeException('STALE_RUN: process exit can be recorded only for an armed process attempt.');
        }

        return $this->copy(
            status: RuntimeStatus::PROCESS_EXITED,
            startedAt: $this->startedAt ?? $result->startedAt,
            finishedAt: $result->finishedAt,
            exitCode: $result->exitCode,
            timedOut: $result->timedOut,
            stdoutLog: self::nonEmpty($stdoutLog, 'stdout log reference'),
            stderrLog: self::nonEmpty($stderrLog, 'stderr log reference'),
        );
    }

    public function withStageResult(StageResult $result, string $candidateRevision): self
    {
        if ($result->submissionId !== $this->submissionId
            || $result->taskId !== $this->taskId
            || $result->runId !== $this->runId
            || $result->contractRevision !== $this->contractRevision
            || !hash_equals($result->executionPlanDigest, $this->executionPlanDigest)
            || $result->stageId !== $this->stageId
            || $result->attempt !== $this->attempt) {
            throw new InvalidArgumentException('Runtime StageResult does not match the persisted attempt identity.');
        }

        return $this->copy(
            status: RuntimeStatus::RESULT_PERSISTED,
            candidateRevision: self::nonEmpty($candidateRevision, 'candidate revision'),
            stageResult: $result,
        );
    }

    public function withStatus(RuntimeStatus $status): self
    {
        return $this->copy(status: $status);
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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Runtime journal requires schema_version 1.');
        }

        $statusValue = self::requiredString($data, 'status');
        $status = RuntimeStatus::tryFrom($statusValue);
        if ($status === null) {
            throw new InvalidArgumentException('Runtime journal contains an unknown status.');
        }

        return new self(
            taskId: self::requiredString($data, 'task_id'),
            runId: self::requiredString($data, 'run_id'),
            contractRevision: self::requiredInt($data, 'contract_revision'),
            executionPlanDigest: self::requiredString($data, 'execution_plan_digest'),
            stageId: self::requiredString($data, 'stage_id'),
            attempt: self::requiredInt($data, 'attempt'),
            submissionId: self::requiredString($data, 'submission_id'),
            status: $status,
            baseCommit: self::requiredString($data, 'base_commit'),
            candidateRevision: self::requiredString($data, 'candidate_revision'),
            hostId: self::nullableString($data, 'host_id'),
            hostVersion: self::nullableString($data, 'host_version'),
            processPid: self::nullableInt($data, 'process_pid'),
            startedAt: self::nullableString($data, 'started_at'),
            finishedAt: self::nullableString($data, 'finished_at'),
            exitCode: self::nullableInt($data, 'exit_code'),
            timedOut: self::requiredBool($data, 'timed_out'),
            stdoutLog: self::nullableString($data, 'stdout_log'),
            stderrLog: self::nullableString($data, 'stderr_log'),
            stageResult: self::stageResult($data['stage_result'] ?? null),
        );
    }

    private function copy(
        ?RuntimeStatus $status = null,
        ?string $candidateRevision = null,
        ?string $hostId = null,
        ?string $hostVersion = null,
        ?int $processPid = null,
        ?string $startedAt = null,
        ?string $finishedAt = null,
        ?int $exitCode = null,
        ?bool $timedOut = null,
        ?string $stdoutLog = null,
        ?string $stderrLog = null,
        ?StageResult $stageResult = null,
    ): self {
        return new self(
            $this->taskId,
            $this->runId,
            $this->contractRevision,
            $this->executionPlanDigest,
            $this->stageId,
            $this->attempt,
            $this->submissionId,
            $status ?? $this->status,
            $this->baseCommit,
            $candidateRevision ?? $this->candidateRevision,
            $hostId ?? $this->hostId,
            $hostVersion ?? $this->hostVersion,
            $processPid ?? $this->processPid,
            $startedAt ?? $this->startedAt,
            $finishedAt ?? $this->finishedAt,
            $exitCode ?? $this->exitCode,
            $timedOut ?? $this->timedOut,
            $stdoutLog ?? $this->stdoutLog,
            $stderrLog ?? $this->stderrLog,
            $stageResult ?? $this->stageResult,
        );
    }

    private static function stageResult(mixed $value): ?StageResult
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Runtime journal stage_result must be an object or null.');
        }

        $outcome = StageOutcome::tryFrom(self::requiredString($value, 'outcome'));
        if ($outcome === null) {
            throw new InvalidArgumentException('Runtime journal stage_result has an unknown outcome.');
        }

        return new StageResult(
            self::requiredString($value, 'submission_id'),
            self::requiredString($value, 'task_id'),
            self::requiredString($value, 'run_id'),
            self::requiredInt($value, 'contract_revision'),
            self::requiredString($value, 'execution_plan_digest'),
            self::requiredString($value, 'stage_id'),
            self::requiredInt($value, 'attempt'),
            $outcome,
            self::requiredString($value, 'candidate_revision'),
            self::stringList($value, 'artifact_references'),
            self::stringList($value, 'validation_references'),
            self::string($value, 'summary'),
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = self::string($data, $key);
        if (trim($value) === '') {
            throw new InvalidArgumentException('Runtime journal requires non-empty string field ' . $key . '.');
        }

        return trim($value);
    }

    /** @param array<array-key, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Runtime journal requires string field ' . $key . '.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Runtime journal field ' . $key . ' must be a non-empty string or null.');
        }

        return trim($value);
    }

    /** @param array<array-key, mixed> $data */
    private static function requiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException('Runtime journal requires integer field ' . $key . '.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidArgumentException('Runtime journal field ' . $key . ' must be an integer or null.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function requiredBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Runtime journal requires boolean field ' . $key . '.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<non-empty-string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Runtime journal requires list field ' . $key . '.');
        }

        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException('Runtime journal field ' . $key . ' requires non-empty strings.');
            }
            $entry = trim($entry);
            if ($entry === '') {
                throw new InvalidArgumentException('Runtime journal field ' . $key . ' requires non-empty strings.');
            }
            /** @var non-empty-string $entry */
            $result[] = $entry;
        }

        return $result;
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
