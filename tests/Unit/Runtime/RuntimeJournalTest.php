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

final class RuntimeJournalTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-journal-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testAtomicallyRoundTripsOnlyBoundedObservationFields(): void
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
            AttemptStatus::ResultPersisted,
            'candidate',
            ['outcome' => 'PASS'],
            ['pid' => 42, 'timed_out' => false],
            completionEnvelope: [
                'outcome' => 'PASS',
                'summary' => 'bounded',
                'artifact_references' => [],
                'validation_references' => [],
            ],
        );
        $journal->save($attempt);
        $loaded = $journal->load('TASK-1');

        self::assertNotNull($loaded);
        self::assertSame($attempt->submissionId, $loaded->submissionId);
        self::assertSame(['outcome' => 'PASS'], $loaded->stageResult);
        self::assertSame($attempt->completionEnvelope, $loaded->completionEnvelope);
        self::assertTrue($loaded->sameAuthority('run-1', 1, 'sha256:plan', 'builder', 1));
        self::assertFalse($loaded->sameAuthority('stale', 1, 'sha256:plan', 'builder', 1));
        self::assertSame([], glob($this->root . '/.agent-loop-runner/runtime/*.tmp-*'));
        self::assertStringNotContainsString(
            'environment',
            (string) file_get_contents((new RunnerLayout($this->root))->runtime('TASK-1')),
        );
    }

    public function testRoundTripsOwnedProcessFingerprintForRestartSafeCancellation(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $attempt = $this->startedAttempt(4242, 'sha256:fingerprint');

        $journal->save($attempt);
        $loaded = $journal->load('TASK-1');

        self::assertNotNull($loaded);
        self::assertSame('sha256:fingerprint', $loaded->process['process_fingerprint'] ?? null);
    }

    public function testCancellationSignalsOnlyExactCurrentProcessObservationAndBecomesTerminal(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $started = $this->startedAttempt(4242, 'sha256:fingerprint');
        $journal->save($started);
        $signalled = 0;

        $cancelled = $journal->cancel($started, static function (RuntimeAttempt $current) use (&$signalled): bool {
            ++$signalled;
            self::assertSame(4242, $current->process['pid'] ?? null);

            return true;
        });

        self::assertSame(1, $signalled);
        self::assertSame(AttemptStatus::Cancelled, $cancelled->status);
        self::assertSame(AttemptStatus::Cancelled, $journal->load('TASK-1')?->status);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cancelled runtime attempt cannot be overwritten');
        $journal->save(new RuntimeAttempt(
            $started->taskId,
            $started->runId,
            $started->contractRevision,
            $started->executionPlanDigest,
            $started->stageId,
            $started->attempt,
            $started->hostId,
            $started->workspaceIdentity,
            $started->submissionId,
            AttemptStatus::ProcessExited,
            process: $started->process,
        ));
    }

    public function testStaleCancellationCannotSignalReplacementProcess(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $stale = $this->startedAttempt(4242, 'sha256:old');
        $journal->save($stale);
        $replacement = $this->startedAttempt(4343, 'sha256:new');
        $journal->save($replacement);
        $signalled = false;

        try {
            $journal->cancel($stale, static function () use (&$signalled): bool {
                $signalled = true;

                return true;
            });
            self::fail('Expected stale cancellation rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('active process observation changed', $exception->getMessage());
        }
        self::assertFalse($signalled, 'A stale PID observation must never reach the signal callback.');
        self::assertSame(4343, $journal->load('TASK-1')?->process['pid'] ?? null);
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

        self::assertSame(2, $journal->load('TASK-1')?->attempt);
        self::assertSame(AttemptStatus::Prepared, $journal->load('TASK-1')?->status);
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
