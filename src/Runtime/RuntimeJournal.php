<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use DateTimeImmutable;
use JsonException;
use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;

final readonly class RuntimeJournal
{
    public function __construct(private RunnerLayout $layout)
    {
    }

    public function load(string $taskId): ?RuntimeAttempt
    {
        return $this->readPath($this->layout->runtime($taskId));
    }

    public function save(RuntimeAttempt $attempt): void
    {
        $path = $this->layout->runtime($attempt->taskId);
        $this->withLock($path, function () use ($attempt, $path): void {
            $current = $this->readPath($path);
            if ($current !== null
                && $current->status === AttemptStatus::Cancelled
                && $current->sameAuthority(
                    $attempt->runId,
                    $attempt->contractRevision,
                    $attempt->executionPlanDigest,
                    $attempt->stageId,
                    $attempt->attempt,
                )
                && $attempt->status !== AttemptStatus::Cancelled) {
                throw new RuntimeException('PROCESS_FAILED: cancelled runtime attempt cannot be overwritten.');
            }
            $this->writePath($path, $attempt);
        });
    }

    /**
     * Retires a runtime record whose workspace has been cleaned up.
     *
     * A record that outlives its workspace keeps describing an identity that can
     * no longer be reconciled, so every later run/resume fails STALE_RUN even
     * after the owner has legitimately re-authorized the work. Callers must have
     * verified the record is reconciled or cancelled before retiring it.
     */
    public function forget(string $taskId): void
    {
        $path = $this->layout->runtime($taskId);
        $this->withLock($path, static function () use ($path): void {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to retire runtime journal record.');
            }
        });
    }

    /**
     * Atomically verifies the exact active process observation, signals it, and
     * persists cancellation before coordinator state may advance.
     *
     * @param callable(RuntimeAttempt): bool $signal
     */
    public function cancel(RuntimeAttempt $expected, callable $signal): RuntimeAttempt
    {
        $path = $this->layout->runtime($expected->taskId);

        return $this->withLock($path, function () use ($expected, $path, $signal): RuntimeAttempt {
            $current = $this->readPath($path);
            if ($current === null
                || $current->status !== AttemptStatus::ProcessStarted
                || !$current->sameAuthority(
                    $expected->runId,
                    $expected->contractRevision,
                    $expected->executionPlanDigest,
                    $expected->stageId,
                    $expected->attempt,
                )
                || !hash_equals($current->submissionId, $expected->submissionId)
                || $current->process !== $expected->process) {
                throw new RuntimeException('PROCESS_FAILED: active process observation changed before cancellation.');
            }
            if (!$signal($current)) {
                throw new RuntimeException('PROCESS_FAILED: owned process no longer exists.');
            }

            $cancelled = new RuntimeAttempt(
                $current->taskId,
                $current->runId,
                $current->contractRevision,
                $current->executionPlanDigest,
                $current->stageId,
                $current->attempt,
                $current->hostId,
                $current->workspaceIdentity,
                $current->submissionId,
                AttemptStatus::Cancelled,
                $current->candidateRevision,
                $current->stageResult,
                $current->process,
                (new DateTimeImmutable())->format(DATE_ATOM),
                $current->completionEnvelope,
            );
            $this->writePath($path, $cancelled);

            return $cancelled;
        });
    }

    private function readPath(string $path): ?RuntimeAttempt
    {
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new CorruptRuntimeJournal('Unable to read runtime journal.');
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CorruptRuntimeJournal('Runtime journal is not valid JSON.', 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new CorruptRuntimeJournal('Runtime journal must be a JSON object.');
        }
        $object = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new CorruptRuntimeJournal('Runtime journal keys must be strings.');
            }
            $object[$key] = $value;
        }

        return RuntimeAttempt::fromArray($object);
    }

    private function writePath(string $path, RuntimeAttempt $attempt): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create runtime journal directory.');
        }
        try {
            $json = json_encode(
                $attempt->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode runtime journal.', 0, $exception);
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create runtime journal temporary file.');
        }
        $complete = false;
        try {
            if (fwrite($handle, $json) !== strlen($json)
                || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Unable to durably write runtime journal.');
            }
            $complete = true;
        } finally {
            fclose($handle);
            if (!$complete && is_file($temporary)) {
                unlink($temporary);
            }
        }
        if (!chmod($temporary, 0o600)) {
            unlink($temporary);
            throw new RuntimeException('Unable to secure runtime journal temporary file permissions.');
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('Unable to atomically replace runtime journal.');
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(string $path, callable $callback): mixed
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create runtime journal directory.');
        }
        $lockPath = $path . '.lock';
        $handle = fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open runtime journal lock.');
        }
        try {
            if (!chmod($lockPath, 0o600)) {
                throw new RuntimeException('Unable to secure runtime journal lock permissions.');
            }
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock runtime journal.');
            }
            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
