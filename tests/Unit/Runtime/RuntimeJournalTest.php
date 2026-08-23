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
    protected function setUp(): void { $this->root = sys_get_temp_dir() . '/runner-journal-' . bin2hex(random_bytes(6)); mkdir($this->root); }
    protected function tearDown(): void { if (is_dir($this->root)) { exec('rm -rf ' . escapeshellarg($this->root)); } }

    public function testAtomicallyRoundTripsOnlyBoundedObservationFields(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $attempt = new RuntimeAttempt('TASK-1', 'run-1', 1, 'sha256:plan', 'builder', 1, 'codex', 'workspace-hash', 'submission-uuid', AttemptStatus::ResultPersisted, 'candidate', ['outcome' => 'PASS'], ['pid' => 42, 'timed_out' => false]);
        $journal->save($attempt);
        $loaded = $journal->load('TASK-1');
        self::assertNotNull($loaded);
        self::assertSame($attempt->submissionId, $loaded->submissionId);
        self::assertSame(['outcome' => 'PASS'], $loaded->stageResult);
        self::assertTrue($loaded->sameAuthority('run-1', 1, 'sha256:plan', 'builder', 1));
        self::assertFalse($loaded->sameAuthority('stale', 1, 'sha256:plan', 'builder', 1));
        self::assertSame([], glob($this->root . '/.agent-loop-runner/runtime/*.tmp-*'));
        self::assertStringNotContainsString('environment', (string) file_get_contents((new RunnerLayout($this->root))->runtime('TASK-1')));
    }

    public function testRoundTripsOwnedProcessFingerprintForRestartSafeCancellation(): void
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
            AttemptStatus::ProcessStarted,
            process: [
                'pid' => 4242,
                'started_at' => '2026-08-23T20:00:00+00:00',
                'process_fingerprint' => 'linux-proc-v1:4242:123456',
            ],
        );

        $journal->save($attempt);
        $loaded = $journal->load('TASK-1');

        self::assertNotNull($loaded);
        self::assertSame('linux-proc-v1:4242:123456', $loaded->process['process_fingerprint'] ?? null);
    }

    public function testRejectsPartialWriteWithoutTreatingItAsState(): void
    {
        $layout = new RunnerLayout($this->root); mkdir(dirname($layout->runtime('TASK')), 0o700, true); file_put_contents($layout->runtime('TASK'), '{"schema_version":');
        $this->expectException(CorruptRuntimeJournal::class);
        (new RuntimeJournal($layout))->load('TASK');
    }

    public function testRejectsUnknownFieldsAndStatuses(): void
    {
        $attempt = new RuntimeAttempt('t', 'r', 1, 'p', 's', 1, 'h', 'w', 'i');
        $data = $attempt->toArray(); $data['environment'] = ['TOKEN' => 'secret'];
        $this->expectException(CorruptRuntimeJournal::class);
        RuntimeAttempt::fromArray($data);
    }
}
