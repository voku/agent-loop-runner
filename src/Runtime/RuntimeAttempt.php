<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RuntimeAttempt
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param non-empty-string|null $candidateRevision
     * @param array<string, mixed>|null $stageResult
     * @param array{pid?: int, started_at?: non-empty-string, exited_at?: non-empty-string, exit_code?: int, timed_out?: bool, stdout_log?: non-empty-string, stderr_log?: non-empty-string, stdout_sha256?: non-empty-string, stderr_sha256?: non-empty-string, stdout_truncated?: bool, stderr_truncated?: bool, process_fingerprint?: non-empty-string} $process
     */
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
        public string $stageId,
        public int $attempt,
        public string $hostId,
        public string $workspaceIdentity,
        public string $submissionId,
        public AttemptStatus $status = AttemptStatus::Prepared,
        public ?string $candidateRevision = null,
        public ?array $stageResult = null,
        public array $process = [],
        public string $updatedAt = '',
    ) {
        foreach ([$taskId, $runId, $executionPlanDigest, $stageId, $hostId, $workspaceIdentity, $submissionId] as $identity) {
            if ($identity === '') {
                throw new InvalidArgumentException('Runtime attempt identities must be non-empty.');
            }
        }
        if ($contractRevision < 1 || $attempt < 1) {
            throw new InvalidArgumentException('Runtime attempt number must be positive.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'stage_id' => $this->stageId,
            'attempt' => $this->attempt,
            'host_id' => $this->hostId,
            'workspace_identity' => $this->workspaceIdentity,
            'submission_id' => $this->submissionId,
            'status' => $this->status->value,
            'candidate_revision' => $this->candidateRevision,
            'stage_result' => $this->stageResult,
            'process' => $this->process,
            'updated_at' => $this->updatedAt !== '' ? $this->updatedAt : (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        self::exactKeys($data);
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new CorruptRuntimeJournal('Unsupported runtime journal schema.');
        }
        $strings = [];
        foreach (['task_id', 'run_id', 'execution_plan_digest', 'stage_id', 'host_id', 'workspace_identity', 'submission_id'] as $key) {
            $value = $data[$key] ?? null;
            if (!is_string($value) || $value === '') {
                throw new CorruptRuntimeJournal('Runtime journal has invalid ' . $key . '.');
            }
            $strings[$key] = $value;
        }
        $contractRevision = $data['contract_revision'] ?? null;
        $attempt = $data['attempt'] ?? null;
        $status = $data['status'] ?? null;
        $updatedAt = $data['updated_at'] ?? null;
        $candidate = $data['candidate_revision'] ?? null;
        $stageResult = $data['stage_result'] ?? null;
        $process = $data['process'] ?? null;
        if (!is_int($contractRevision) || $contractRevision < 1 || !is_int($attempt) || $attempt < 1 || !is_string($status) || !is_string($updatedAt) || $updatedAt === ''
            || ($candidate !== null && (!is_string($candidate) || $candidate === ''))
            || ($stageResult !== null && !is_array($stageResult)) || !is_array($process)) {
            throw new CorruptRuntimeJournal('Runtime journal field types are invalid.');
        }
        $parsedStatus = AttemptStatus::tryFrom($status);
        if ($parsedStatus === null) {
            throw new CorruptRuntimeJournal('Runtime journal status is invalid.');
        }
        $safeProcess = self::process($process);
        $safeStageResult = $stageResult === null ? null : self::associative($stageResult, 'stage_result');

        return new self(
            $strings['task_id'], $strings['run_id'], $contractRevision, $strings['execution_plan_digest'],
            $strings['stage_id'], $attempt, $strings['host_id'], $strings['workspace_identity'], $strings['submission_id'],
            $parsedStatus, $candidate, $safeStageResult, $safeProcess, $updatedAt,
        );
    }

    public function sameAuthority(string $runId, int $contractRevision, string $planDigest, string $stageId, int $attempt): bool
    {
        return hash_equals($this->runId, $runId)
            && $this->contractRevision === $contractRevision
            && hash_equals($this->executionPlanDigest, $planDigest)
            && hash_equals($this->stageId, $stageId)
            && $this->attempt === $attempt;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function associative(array $value, string $field): array
    {
        $result = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new CorruptRuntimeJournal('Runtime journal ' . $field . ' must be an object.');
            }
            $result[$key] = $entry;
        }
        return $result;
    }

    /** @param array<string, mixed> $data */
    private static function exactKeys(array $data): void
    {
        $expected = ['attempt', 'candidate_revision', 'contract_revision', 'execution_plan_digest', 'host_id', 'process', 'run_id', 'schema_version', 'stage_id', 'stage_result', 'status', 'submission_id', 'task_id', 'updated_at', 'workspace_identity'];
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== $expected) {
            throw new CorruptRuntimeJournal('Runtime journal contains missing or unknown fields.');
        }
    }

    /**
     * @param array<mixed> $process
     * @return array{pid?: int, started_at?: non-empty-string, exited_at?: non-empty-string, exit_code?: int, timed_out?: bool, stdout_log?: non-empty-string, stderr_log?: non-empty-string, stdout_sha256?: non-empty-string, stderr_sha256?: non-empty-string, stdout_truncated?: bool, stderr_truncated?: bool, process_fingerprint?: non-empty-string}
     */
    private static function process(array $process): array
    {
        $allowed = ['pid', 'started_at', 'exited_at', 'exit_code', 'timed_out', 'stdout_log', 'stderr_log', 'stdout_sha256', 'stderr_sha256', 'stdout_truncated', 'stderr_truncated', 'process_fingerprint'];
        foreach (array_keys($process) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new CorruptRuntimeJournal('Runtime journal process metadata contains an unknown field.');
            }
        }
        $result = [];
        foreach (['pid', 'exit_code'] as $key) {
            if (isset($process[$key])) {
                if (!is_int($process[$key])) throw new CorruptRuntimeJournal('Invalid process integer metadata.');
                $result[$key] = $process[$key];
            }
        }
        foreach (['started_at', 'exited_at', 'stdout_log', 'stderr_log', 'stdout_sha256', 'stderr_sha256', 'process_fingerprint'] as $key) {
            if (isset($process[$key])) {
                if (!is_string($process[$key]) || $process[$key] === '') throw new CorruptRuntimeJournal('Invalid process string metadata.');
                $result[$key] = $process[$key];
            }
        }
        foreach (['timed_out', 'stdout_truncated', 'stderr_truncated'] as $key) {
            if (isset($process[$key])) {
                if (!is_bool($process[$key])) throw new CorruptRuntimeJournal('Invalid process boolean metadata.');
                $result[$key] = $process[$key];
            }
        }
        return $result;
    }
}
