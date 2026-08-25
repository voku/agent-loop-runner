<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\CorruptRuntimeJournal;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;

final class RuntimeJournalTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-runner-journal-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testRoundTripsAttempt(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $attempt = new RuntimeAttempt(
            'TASK-1',
            'run-1',
            1,
            'sha256:plan',
            'builder',
            1,
            'codex',
            'workspace-hash',
            'submission-uuid',
            AttemptStatus::Prepared,
            'candidate-1',
            ['accepted' => true],
            ['pid' => 1234],
            '2026-08-23T20:00:00+00:00',
            [
                'outcome' => 'done',
                'summary' => 'fixture',
                'artifact_references' => ['artifact-1'],
                'validation_references' => ['validation-1'],
            ],
        );
        $journal->save($attempt);

        self::assertSame($attempt->toArray(), $journal->load('TASK-1')?->toArray());
    }

    public function testCancelledAttemptCannotBeOverwrittenBySameAuthoritativeAttempt(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $started = $this->startedAttempt(4242, 'sha256:fingerprint');
        $journal->save($started);
        $journal->cancel($started, static fn (): bool => true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cancelled runtime attempt cannot be overwritten');
        $journal->save($started);
    }

    public function testCancellationFailsWhenActiveProcessObservationChanged(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $started = $this->startedAttempt(4242, 'sha256:fingerprint');
        $journal->save($started);

        $changed = $this->startedAttempt(4243, 'sha256:other');
        $journal->save($changed);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('active process observation changed before cancellation');
        $journal->cancel($started, static fn (): bool => true);
    }

    public function testCancellationPersistsBeforeReturning(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $started = $this->startedAttempt(4242, 'sha256:fingerprint');
        $journal->save($started);

        $cancelled = $journal->cancel($started, static fn (): bool => true);
        $loaded = $journal->load('TASK-1');
        self::assertNotNull($loaded);

        self::assertSame(AttemptStatus::Cancelled, $cancelled->status);
        self::assertSame(AttemptStatus::Cancelled, $loaded->status);
    }

    public function testCancellationFailsWhenSignalFails(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $started = $this->startedAttempt(4242, 'sha256:fingerprint');
        $journal->save($started);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('owned process no longer exists');
        $journal->cancel($started, static fn (): bool => false);
    }

    public function testCancelledAttemptMayBeReplacedOnlyByDifferentAuthoritativeAttempt(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $started = $this->startedAttempt(4242, 'sha256:fingerprint');
        $journal->save($started);
        $journal->cancel($started, static fn (): bool => true);

        $next = new RuntimeAttempt(
            'TASK-1',
            'run-1',
            1,
            'sha256:plan',
            'builder',
            2,
            'codex',
            'workspace-hash',
            'submission-next',
        );
        $journal->save($next);

        $loaded = $journal->load('TASK-1');
        self::assertNotNull($loaded);
        self::assertSame(2, $loaded->attempt);
        self::assertSame(AttemptStatus::Prepared, $loaded->status);
    }

    public function testRejectsPartialWriteWithoutTreatingItAsState(): void
    {
        $layout = new RunnerLayout($this->root);
        mkdir(dirname($layout->runtime('TASK')), 0o700, true);
        file_put_contents($layout->runtime('TASK'), '{"schema_version":');

        $this->expectException(CorruptRuntimeJournal::class);
        (new RuntimeJournal($layout))->load('TASK');
    }

    public function testRejectsUnknownFieldsAndStatuses(): void
    {
        $attempt = new RuntimeAttempt('t', 'r', 1, 'p', 's', 1, 'h', 'w', 'i');
        $data = $attempt->toArray();
        $data['environment'] = ['TOKEN' => 'secret'];

        $this->expectException(CorruptRuntimeJournal::class);
        RuntimeAttempt::fromArray($data);
    }

    /** @param non-empty-string $fingerprint */
    private function startedAttempt(int $pid, string $fingerprint): RuntimeAttempt
    {
        return new RuntimeAttempt(
            'TASK-1',
            'run-1',
            1,
            'sha256:plan',
            'builder',
            1,
            'codex',
            'workspace-hash',
            'submission-uuid',
            AttemptStatus::ProcessStarted,
            process: [
                'pid' => $pid,
                'started_at' => '2026-08-23T20:00:00+00:00',
                'process_fingerprint' => $fingerprint,
            ],
        );
    }
}
