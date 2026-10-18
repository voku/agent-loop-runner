<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\Application\ExitCode;
use voku\AgentLoopRunner\Application\RunnerApplication;
use voku\AgentLoopRunner\Application\RunnerControlService;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\ProcessIdentity;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

/** The explicit operator exit for a Run wedged by a Runner crash (agent-loop-runner#45, option B). */
final class RunnerAbandonTest extends TestCase
{
    private string $root;
    private string $base;
    private RunnerLayout $layout;
    private RuntimeJournal $journal;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-abandon-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o700, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@example.test']);
        $this->git(['config', 'user.name', 'Test']);
        file_put_contents($this->root . '/tracked.txt', "base\n");
        $this->git(['add', '.']);
        $this->git(['commit', '-qm', 'base']);
        $this->base = trim($this->git(['rev-parse', 'HEAD']));
        $this->layout = new RunnerLayout($this->root);
        $this->journal = new RuntimeJournal($this->layout);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testDeadProcessIsAbandonedWithoutAnyAgentLoopState(): void
    {
        $this->journal->save($this->started($this->deadPid(), 'sha256:recorded'));

        $attempt = $this->controls()->abandon('TASK', 'lars', 'Runner was killed');

        self::assertSame(AttemptStatus::Cancelled, $attempt->status);
        self::assertNull($attempt->stageResult);
        self::assertNull($attempt->completionEnvelope);
        self::assertSame('lars', $attempt->process['abandoned_by'] ?? null);
        self::assertSame('Runner was killed', $attempt->process['abandoned_reason'] ?? null);
        self::assertSame(AttemptStatus::Cancelled, $this->journal->load('TASK')?->status);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop', 'abandon must not need or create Loop state.');
    }

    public function testReusedPidWithADifferentFingerprintCountsAsGone(): void
    {
        // An unrelated live process (not a group leader) that now owns the recorded pid.
        $process = proc_open([PHP_BINARY, '-r', 'sleep(20);'], [], $pipes);
        self::assertIsResource($process);
        try {
            $pid = proc_get_status($process)['pid'];
            $this->journal->save($this->started($pid, 'sha256:somebody-else'));

            self::assertSame(AttemptStatus::Cancelled, $this->controls()->abandon('TASK', 'lars', 'pid was reused')->status);
        } finally {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
    }

    public function testLiveMatchingProcessIsRefusedWithAPointerToCancel(): void
    {
        $pid = (int) getmypid();
        $fingerprint = ProcessIdentity::fingerprint($pid);
        self::assertNotNull($fingerprint, 'this platform must be able to fingerprint a live process');
        $this->journal->save($this->started($pid, $fingerprint));
        $before = $this->bytes();

        try {
            $this->controls()->abandon('TASK', 'lars', 'still running');
            self::fail('A live matching process must not be abandoned.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('use cancel', $exception->getMessage());
        }
        self::assertSame($before, $this->bytes());
    }

    public function testLeaderGoneButProcessGroupStillAliveIsRefused(): void
    {
        if (!is_executable('/usr/bin/setsid')) {
            self::markTestSkipped('setsid is required to build a process group that outlives its leader.');
        }
        // The leader (the recorded pid) exits at once; its background child keeps the group alive.
        $process = proc_open(['/usr/bin/setsid', 'sh', '-c', 'sleep 20 & exit 0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $groupLeader = 0;
        try {
            for ($i = 0; $i < 50 && $groupLeader === 0; ++$i) {
                $groupLeader = (int) trim((string) shell_exec('pgrep -n -x sleep'));
                usleep(100_000);
            }
            self::assertGreaterThan(0, $groupLeader);
            $pgid = (int) trim((string) shell_exec('ps -o pgid= -p ' . $groupLeader));
            self::assertGreaterThan(0, $pgid);
            // Wait until the leader is gone but the group (== pgid) is still reachable.
            for ($i = 0; $i < 50 && posix_kill($pgid, 0); ++$i) {
                usleep(100_000);
            }
            $this->journal->save($this->started($pgid, 'sha256:recorded'));
            $before = $this->bytes();

            try {
                $this->controls()->abandon('TASK', 'lars', 'leader exited');
                self::fail('A group with live members must not be abandoned.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('process group still has live members', $exception->getMessage());
            }
            self::assertSame($before, $this->bytes());
        } finally {
            if (isset($pgid) && $pgid > 0) {
                posix_kill(-$pgid, SIGKILL);
            }
            proc_close($process);
        }
    }

    public function testMissingRecordedFingerprintCannotProveTheProcessIsGone(): void
    {
        $this->journal->save(new RuntimeAttempt(
            'TASK', 'RUN', 1, 'sha256:plan', 'builder', 1, 'claude', 'workspace', 'submission-1',
            AttemptStatus::ProcessStarted,
            process: ['pid' => $this->deadPid(), 'started_at' => '2026-10-18T10:00:00+00:00'],
        ));
        $before = $this->bytes();

        try {
            $this->controls()->abandon('TASK', 'lars', 'gone');
            self::fail('An incomplete identity must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('identity is incomplete', $exception->getMessage());
        }
        self::assertSame($before, $this->bytes());
    }

    public function testNothingToAbandonWhenNoProcessIsRecorded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no owned active process is recorded');
        $this->controls()->abandon('TASK', 'lars', 'gone');
    }

    public function testUsageErrorsChangeNothing(): void
    {
        $this->journal->save($this->started($this->deadPid(), 'sha256:recorded'));
        $before = $this->bytes();
        $app = new RunnerApplication($this->root);

        foreach ([
            ['abandon', 'TASK'],
            ['abandon', 'TASK', '--by', 'lars'],
            ['abandon', 'TASK', '--reason', 'gone'],
            ['abandon', 'TASK', '--by', ' ', '--reason', 'gone'],
            ['abandon', 'TASK', '--by', 'lars', '--reason'],
            ['abandon', 'TASK', '--by', 'lars', '--reason', 'gone', '--force', 'yes'],
            ['abandon'],
        ] as $arguments) {
            ob_start();
            $exit = $app->run($arguments);
            ob_end_clean();
            self::assertSame(ExitCode::USAGE, $exit, implode(' ', $arguments));
            self::assertSame($before, $this->bytes());
        }
    }

    public function testCleanupSucceedsOnACleanWorktreeAfterAbandon(): void
    {
        $workspace = $this->acquireWorkspace();
        $this->journal->save($this->started($this->deadPid(), 'sha256:recorded', (string) hash('sha256', $workspace->lease->path)));
        $this->controls()->abandon('TASK', 'lars', 'Runner was killed');

        $this->controls()->cleanup('TASK');

        self::assertNull($this->journal->load('TASK'));
        self::assertDirectoryDoesNotExist($workspace->lease->path);
    }

    public function testCleanupStillRefusesADirtyWorktreeAfterAbandon(): void
    {
        $workspace = $this->acquireWorkspace();
        file_put_contents($workspace->lease->path . '/candidate.txt', 'unreviewed provider output');
        $this->journal->save($this->started($this->deadPid(), 'sha256:recorded', (string) hash('sha256', $workspace->lease->path)));
        $this->controls()->abandon('TASK', 'lars', 'Runner was killed');

        try {
            $this->controls()->cleanup('TASK');
            self::fail('A dirty worktree must keep failing closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Refusing to remove dirty Run workspace', $exception->getMessage());
        }
        self::assertSame(AttemptStatus::Cancelled, $this->journal->load('TASK')?->status);
        self::assertFileExists($workspace->lease->path . '/candidate.txt');
    }

    private function controls(): RunnerControlService
    {
        return new RunnerControlService($this->root);
    }

    /** @param non-empty-string $fingerprint */
    private function started(int $pid, string $fingerprint, string $workspaceIdentity = 'workspace'): RuntimeAttempt
    {
        return new RuntimeAttempt(
            'TASK', 'RUN', 1, 'sha256:plan', 'builder', 1, 'claude', $workspaceIdentity, 'submission-1',
            AttemptStatus::ProcessStarted,
            process: ['pid' => $pid, 'started_at' => '2026-10-18T10:00:00+00:00', 'process_fingerprint' => $fingerprint],
        );
    }

    /** A pid whose process has exited and been reaped, so the kernel no longer knows it. */
    private function deadPid(): int
    {
        $process = proc_open([PHP_BINARY, '-r', 'exit(0);'], [], $pipes);
        self::assertIsResource($process);
        $status = proc_get_status($process);
        $pid = $status['pid'];
        proc_close($process);
        self::assertFalse(posix_kill($pid, 0), 'fixture pid must be gone');

        return $pid;
    }

    private function acquireWorkspace(): \voku\AgentLoopRunner\Workspace\ManagedWorkspace
    {
        $command = new GitCommand(new ForegroundProcessSupervisor(), ['PATH' => (string) getenv('PATH')]);
        $workspace = (new RunWorkspaceManager(
            $this->layout,
            new GitWorktreeService($command),
            new WorkspaceCandidateHasher($command),
        ))->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        $workspace->mutationLock?->release();

        return $workspace;
    }

    private function bytes(): string
    {
        $bytes = file_get_contents($this->layout->runtime('TASK'));
        self::assertIsString($bytes);

        return $bytes;
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = proc_open(['git', '-C', $this->root, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return (string) $stdout;
    }
}
