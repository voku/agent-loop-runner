<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RuntimeAttempt
{
    public const int SCHEMA_VERSION = 2;

    private const int MAX_SUMMARY_BYTES = 4_000;
    private const int MAX_REFERENCE_BYTES = 1_000;
    private const int MAX_REFERENCES = 100;

    /**
     * @param non-empty-string|null $candidateRevision
     * @param array<string, mixed>|null $stageResult
     * @param array{pid?: int, started_at?: non-empty-string, exited_at?: non-empty-string, exit_code?: int, timed_out?: bool, stdout_log?: non-empty-string, stderr_log?: non-empty-string, stdout_sha256?: non-empty-string, stderr_sha256?: non-empty-string, stdout_truncated?: bool, stderr_truncated?: bool, process_fingerprint?: non-empty-string} $process
     * @param array{outcome: non-empty-string, summary: non-empty-string, artifact_references: list<non-empty-string>, validation_references: list<non-empty-string>}|null $completionEnvelope
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
        public ?array $completionEnvelope = null,
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
            'completion_envelope' => $this->completionEnvelope,
            'updated_at' => $this->updatedAt !== '' ? $this->updatedAt : (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $schemaVersion = $data['schema_version'] ?? null;
        if (!is_int($schemaVersion) || !in_array($schemaVersion, [1, self::SCHEMA_VERSION], true)) {
            throw new CorruptRuntimeJournal('Unsupported runtime journal schema.');
        }
        self::exactKeys($data, $schemaVersion);

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
        $completionEnvelope = $schemaVersion === 1
            ? null
            : self::completionEnvelope($data['completion_envelope'] ?? null);

        return new self(
            $strings['task_id'],
            $strings['run_id'],
            $contractRevision,
            $strings['execution_plan_digest'],
            $strings['stage_id'],
            $attempt,
            $strings['host_id'],
            $strings['workspace_identity'],
            $strings['submission_id'],
            $parsedStatus,
            $candidate,
            $safeStageResult,
            $safeProcess,
            $updatedAt,
            $completionEnvelope,
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
    private static function exactKeys(array $data, int $schemaVersion): void
    {
        $expected = ['attempt', 'candidate_revision', 'contract_revision', 'execution_plan_digest', 'host_id', 'process', 'run_id', 'schema_version', 'stage_id', 'stage_result', 'status', 'submission_id', 'task_id', 'updated_at', 'workspace_identity'];
        if ($schemaVersion >= 2) {
            $expected[] = 'completion_envelope';
        }
        sort($expected);
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
                if (!is_int($process[$key])) {
                    throw new CorruptRuntimeJournal('Invalid process integer metadata.');
                }
                $result[$key] = $process[$key];
            }
        }
        foreach (['started_at', 'exited_at', 'stdout_log', 'stderr_log', 'stdout_sha256', 'stderr_sha256', 'process_fingerprint'] as $key) {
            if (isset($process[$key])) {
                if (!is_string($process[$key]) || $process[$key] === '') {
                    throw new CorruptRuntimeJournal('Invalid process string metadata.');
                }
                $result[$key] = $process[$key];
            }
        }
        foreach (['timed_out', 'stdout_truncated', 'stderr_truncated'] as $key) {
            if (isset($process[$key])) {
                if (!is_bool($process[$key])) {
                    throw new CorruptRuntimeJournal('Invalid process boolean metadata.');
                }
                $result[$key] = $process[$key];
            }
        }

        return $result;
    }

    /**
     * @return array{outcome: non-empty-string, summary: non-empty-string, artifact_references: list<non-empty-string>, validation_references: list<non-empty-string>}|null
     */
    private static function completionEnvelope(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new CorruptRuntimeJournal('Runtime journal completion envelope must be an object.');
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['artifact_references', 'outcome', 'summary', 'validation_references']) {
            throw new CorruptRuntimeJournal('Runtime journal completion envelope fields are invalid.');
        }
        $outcome = $value['outcome'] ?? null;
        $summary = $value['summary'] ?? null;
        if (!is_string($outcome) || $outcome === '' || !is_string($summary) || $summary === '' || strlen($summary) > self::MAX_SUMMARY_BYTES) {
            throw new CorruptRuntimeJournal('Runtime journal completion envelope scalar fields are invalid.');
        }

        return [
            'outcome' => $outcome,
            'summary' => $summary,
            'artifact_references' => self::completionReferences($value['artifact_references'] ?? null),
            'validation_references' => self::completionReferences($value['validation_references'] ?? null),
        ];
    }

    /** @return list<non-empty-string> */
    private static function completionReferences(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_REFERENCES) {
            throw new CorruptRuntimeJournal('Runtime journal completion references are invalid.');
        }
        $result = [];
        foreach ($value as $reference) {
            if (!is_string($reference) || $reference === '' || strlen($reference) > self::MAX_REFERENCE_BYTES) {
                throw new CorruptRuntimeJournal('Runtime journal completion reference is invalid.');
            }
            $result[] = $reference;
        }

        return $result;
    }
}
