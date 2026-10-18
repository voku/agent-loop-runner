<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoopRunner\Application\RunnerControlService;
use voku\AgentLoopRunner\Application\RunnerStatus;
use voku\AgentLoopRunner\Process\ProcessIdentity;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;

/** controls.cancel must be advertised only for a process that cancel() can actually signal (#45). */
final class RunnerStatusCancellationTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-status-cancel-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testMatchingLiveGroupLeaderAdvertisesCancelAndCancelCanSignalIt(): void
    {
        [$pid, $fingerprint] = $this->liveGroupLeader();
        $attempt = $this->started(['pid' => $pid, 'process_fingerprint' => $fingerprint]);

        self::assertTrue((new RunnerStatus($this->authority(), $attempt))->allows(RunnerStatus::CANCEL));

        // The advertised control is the one that works: cancel() really terminates the group.
        (new RuntimeJournal(new RunnerLayout($this->root)))->save($attempt);
        $cancelled = (new RunnerControlService($this->root))->cancel('TASK-1');
        self::assertSame(AttemptStatus::Cancelled, $cancelled->status);
    }

    public function testReusedPidAdvertisesNoCancel(): void
    {
        [$pid] = $this->liveGroupLeader();

        self::assertFalse($this->cancelAdvertised(['pid' => $pid, 'process_fingerprint' => 'sha256:previous-owner']));
    }

    public function testVanishedPidAdvertisesNoCancel(): void
    {
        $process = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [], $pipes);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        $fingerprint = ProcessIdentity::fingerprint($pid);
        self::assertNotNull($fingerprint);
        self::assertTrue(proc_terminate($process, SIGKILL));
        proc_close($process);
        self::assertFalse(posix_kill($pid, 0), 'fixture pid must be gone');

        self::assertFalse($this->cancelAdvertised(['pid' => $pid, 'process_fingerprint' => $fingerprint]));
    }

    public function testLiveMatchingProcessThatIsNotAGroupLeaderAdvertisesNoCancel(): void
    {
        $process = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;
        $pid = proc_get_status($process)['pid'];
        $fingerprint = ProcessIdentity::fingerprint($pid);
        self::assertNotNull($fingerprint);

        self::assertFalse($this->cancelAdvertised(['pid' => $pid, 'process_fingerprint' => $fingerprint]));
    }

    /** @return iterable<string, array{array{pid?: int, process_fingerprint?: non-empty-string}}> */
    public static function incompleteEvidence(): iterable
    {
        yield 'no evidence' => [[]];
        yield 'pid only' => [['pid' => 4242]];
        yield 'fingerprint only' => [['process_fingerprint' => 'sha256:x']];
        yield 'pid that cannot be inspected' => [['pid' => 0, 'process_fingerprint' => 'sha256:x']];
    }

    /** @param array{pid?: int, process_fingerprint?: non-empty-string} $process */
    #[DataProvider('incompleteEvidence')]
    public function testMissingOrUninspectableIdentityAdvertisesNoCancel(array $process): void
    {
        self::assertFalse($this->cancelAdvertised($process));
    }

    /** @return iterable<string, array{AttemptStatus}> */
    public static function otherStatuses(): iterable
    {
        foreach ([AttemptStatus::Prepared, AttemptStatus::ProcessExited, AttemptStatus::ReconciledAccepted, AttemptStatus::Cancelled, AttemptStatus::Failed] as $status) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('otherStatuses')]
    public function testOnlyProcessStartedCanAdvertiseCancelEvenWithALiveLeader(AttemptStatus $status): void
    {
        [$pid, $fingerprint] = $this->liveGroupLeader();

        self::assertFalse(
            (new RunnerStatus($this->authority(), $this->started(['pid' => $pid, 'process_fingerprint' => $fingerprint], $status)))
                ->allows(RunnerStatus::CANCEL),
        );
    }

    public function testStatusProjectionNeverChangesTheJournal(): void
    {
        [$pid, $fingerprint] = $this->liveGroupLeader();
        $attempt = $this->started(['pid' => $pid, 'process_fingerprint' => $fingerprint]);
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $journal->save($attempt);
        $path = (new RunnerLayout($this->root))->runtime('TASK-1');
        $before = file_get_contents($path);

        $loaded = $journal->load('TASK-1');
        self::assertNotNull($loaded);
        $status = new RunnerStatus($this->authority(), $loaded);
        $status->allows(RunnerStatus::CANCEL);
        $status->toArray();

        self::assertSame($before, file_get_contents($path));
        self::assertNotFalse(posix_getpgid($pid), 'status must not signal or stop the process');
    }

    /** @param array{pid?: int, process_fingerprint?: non-empty-string} $process */
    private function cancelAdvertised(array $process): bool
    {
        return (new RunnerStatus($this->authority(), $this->started($process)))->allows(RunnerStatus::CANCEL);
    }

    /**
     * @param array{pid?: int, process_fingerprint?: non-empty-string} $process
     */
    private function started(array $process, AttemptStatus $status = AttemptStatus::ProcessStarted): RuntimeAttempt
    {
        return new RuntimeAttempt(
            'TASK-1', 'run:TASK-1', 2, 'sha256:plan', 'implementation', 3, 'claude', 'sha256:workspace', 'submission-1',
            $status,
            process: $process,
        );
    }

    private function authority(): ExecutionProjection
    {
        return new ExecutionProjection('TASK-1', 'run:TASK-1', 2, ExecutionProfileName::SURGICAL, 'sha256:plan', 'implementation', 3, null, [], 'abc123');
    }

    /** @return array{int, non-empty-string} */
    private function liveGroupLeader(): array
    {
        if (!is_executable('/usr/bin/setsid')) {
            self::markTestSkipped('setsid is required to start a process-group leader.');
        }
        $process = proc_open(['/usr/bin/setsid', 'sleep', '30'], [], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;
        $pid = proc_get_status($process)['pid'];
        for ($i = 0; $i < 50 && posix_getpgid($pid) !== $pid; ++$i) {
            usleep(100_000);
        }
        self::assertSame($pid, posix_getpgid($pid), 'fixture must lead its own process group');
        $fingerprint = ProcessIdentity::fingerprint($pid);
        self::assertNotNull($fingerprint);

        return [$pid, $fingerprint];
    }
}
