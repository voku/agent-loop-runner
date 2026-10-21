<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\CorruptRuntimeJournal;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;

final class RuntimeJournalAbandonTest extends TestCase
{
    private string $root;
    private RunnerLayout $layout;
    private RuntimeJournal $journal;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-runner-abandon-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
        $this->layout = new RunnerLayout($this->root);
        $this->journal = new RuntimeJournal($this->layout);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAbandonKeepsIdentityRecordsProvenanceAndReloads(): void
    {
        $started = $this->started();
        $this->journal->save($started);

        $abandoned = $this->journal->abandon($started, ' lars ', ' runner was killed ', static fn (): bool => true);
        $loaded = $this->journal->load('TASK-1');

        self::assertNotNull($loaded);
        foreach ([$abandoned, $loaded] as $attempt) {
            self::assertSame(AttemptStatus::Cancelled, $attempt->status);
            self::assertNull($attempt->stageResult);
            self::assertNull($attempt->completionEnvelope);
            self::assertSame($started->runId, $attempt->runId);
            self::assertSame($started->contractRevision, $attempt->contractRevision);
            self::assertSame($started->executionPlanDigest, $attempt->executionPlanDigest);
            self::assertSame($started->stageId, $attempt->stageId);
            self::assertSame($started->attempt, $attempt->attempt);
            self::assertSame($started->submissionId, $attempt->submissionId);
            self::assertSame(4242, $attempt->process['pid'] ?? null);
            self::assertSame('lars', $attempt->process['abandoned_by'] ?? null);
            self::assertSame('runner was killed', $attempt->process['abandoned_reason'] ?? null);
            self::assertNotSame('', $attempt->process['abandoned_at'] ?? '');
        }
    }

    public function testAbandonedAttemptCannotBeOverwrittenByTheSameAttempt(): void
    {
        $started = $this->started();
        $this->journal->save($started);
        $this->journal->abandon($started, 'lars', 'gone', static fn (): bool => true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PROCESS_CANCELLED: provider attempt was cancelled before its result was recorded.');
        $this->journal->save($started);
    }

    public function testLiveProcessIsRefusedAndJournalIsUnchanged(): void
    {
        $started = $this->started();
        $this->journal->save($started);
        $before = $this->bytes();

        try {
            $this->journal->abandon($started, 'lars', 'gone', static fn (): bool => false);
            self::fail('A still-running process must not be abandoned.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('still running; use cancel', $exception->getMessage());
        }
        self::assertSame($before, $this->bytes());
    }

    public function testIdentityCheckFailureIsRefusedAndJournalIsUnchanged(): void
    {
        $started = $this->started();
        $this->journal->save($started);
        $before = $this->bytes();

        try {
            $this->journal->abandon($started, 'lars', 'gone', static function (): bool {
                throw new RuntimeException('PROCESS_FAILED: cannot prove it is gone.');
            });
            self::fail('An unprovable identity must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot prove', $exception->getMessage());
        }
        self::assertSame($before, $this->bytes());
    }

    /** @return iterable<string, array{AttemptStatus}> */
    public static function otherStatuses(): iterable
    {
        foreach ([AttemptStatus::Prepared, AttemptStatus::ProcessExited, AttemptStatus::ReconciledAccepted, AttemptStatus::Cancelled, AttemptStatus::Failed] as $status) {
            yield $status->value => [$status];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('otherStatuses')]
    public function testOnlyProcessStartedCanBeAbandoned(AttemptStatus $status): void
    {
        $current = $this->started($status);
        $this->journal->save($current);
        $before = $this->bytes();

        try {
            $this->journal->abandon($current, 'lars', 'gone', static fn (): bool => true);
            self::fail('Only process_started may be abandoned.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('observation changed before abandon', $exception->getMessage());
        }
        self::assertSame($before, $this->bytes());
    }

    public function testChangedObservationIsRefused(): void
    {
        $original = $this->started();
        $this->journal->save($this->started(AttemptStatus::ProcessStarted, 4243, 'sha256:other'));
        $before = $this->bytes();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('observation changed before abandon');
        try {
            $this->journal->abandon($original, 'lars', 'gone', static fn (): bool => true);
        } finally {
            self::assertSame($before, $this->bytes());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function badEvidence(): iterable
    {
        yield 'empty actor' => ['  ', 'reason'];
        yield 'empty reason' => ['lars', "\t"];
        yield 'oversized actor' => [str_repeat('a', 201), 'reason'];
        yield 'oversized reason' => ['lars', str_repeat('a', 1001)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badEvidence')]
    public function testActorAndReasonAreRequiredAndBounded(string $by, string $reason): void
    {
        $started = $this->started();
        $this->journal->save($started);
        $before = $this->bytes();

        try {
            $this->journal->abandon($started, $by, $reason, static fn (): bool => true);
            self::fail('Missing or oversized evidence must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('abandon needs a non-empty actor', $exception->getMessage());
        }
        self::assertSame($before, $this->bytes());
    }

    public function testOnlyTheThreeProvenanceFieldsAreNewlyAllowedInProcessMetadata(): void
    {
        $path = $this->layout->runtime('TASK-1');
        $record = $this->started(AttemptStatus::Cancelled)->toArray();

        $record['process'] = ['pid' => 4242, 'abandoned_by' => 'lars', 'abandoned_reason' => 'gone', 'abandoned_at' => '2026-10-18T10:00:00+00:00'];
        $this->write($path, $record);
        self::assertSame('lars', $this->journal->load('TASK-1')?->process['abandoned_by'] ?? null);

        $record['process'] = ['pid' => 4242, 'abandoned_by' => 'lars', 'evil' => 'x'];
        $this->write($path, $record);
        $this->expectException(CorruptRuntimeJournal::class);
        $this->expectExceptionMessage('unknown field');
        $this->journal->load('TASK-1');
    }

    public function testProvenanceFieldsMustBeNonEmptyStrings(): void
    {
        $record = $this->started(AttemptStatus::Cancelled)->toArray();
        $record['process'] = ['pid' => 4242, 'abandoned_by' => 7];
        $this->write($this->layout->runtime('TASK-1'), $record);

        $this->expectException(CorruptRuntimeJournal::class);
        $this->expectExceptionMessage('Invalid process string metadata');
        $this->journal->load('TASK-1');
    }

    /** @param non-empty-string $fingerprint */
    private function started(AttemptStatus $status = AttemptStatus::ProcessStarted, int $pid = 4242, string $fingerprint = 'sha256:fingerprint'): RuntimeAttempt
    {
        return new RuntimeAttempt(
            'TASK-1',
            'run-1',
            1,
            'sha256:plan',
            'builder',
            1,
            'claude',
            'workspace-hash',
            'submission-uuid',
            $status,
            process: [
                'pid' => $pid,
                'started_at' => '2026-10-18T10:00:00+00:00',
                'process_fingerprint' => $fingerprint,
            ],
        );
    }

    private function bytes(): string
    {
        $bytes = file_get_contents($this->layout->runtime('TASK-1'));
        self::assertIsString($bytes);

        return $bytes;
    }

    /** @param array<string, mixed> $record */
    private function write(string $path, array $record): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o700, true);
        }
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
    }
}
