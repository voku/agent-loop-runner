<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\Application\RunnerControlService;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

final class RunnerCleanupRecoveryTest extends TestCase
{
    private string $root;
    private string $source;
    private string $base;
    private RunnerLayout $layout;
    private RunWorkspaceManager $workspaces;
    private RuntimeJournal $journal;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-cleanup-recovery-' . bin2hex(random_bytes(5));
        $this->source = $this->root . '/source';
        mkdir($this->source, 0o700, true);
        $this->git($this->source, ['init', '-q']);
        $this->git($this->source, ['config', 'user.email', 'test@example.test']);
        $this->git($this->source, ['config', 'user.name', 'Test']);
        file_put_contents($this->source . '/tracked.txt', "base\n");
        $this->git($this->source, ['add', '.']);
        $this->git($this->source, ['commit', '-qm', 'base']);
        $this->base = trim($this->git($this->source, ['rev-parse', 'HEAD']));

        $this->layout = new RunnerLayout($this->source);
        $command = new GitCommand(new ForegroundProcessSupervisor(), ['PATH' => (string) getenv('PATH')]);
        $this->workspaces = new RunWorkspaceManager(
            $this->layout,
            new GitWorktreeService($command),
            new WorkspaceCandidateHasher($command),
        );
        $this->journal = new RuntimeJournal($this->layout);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testCleanFailedProviderAttemptCanBeCleanedWithoutRedispatch(): void
    {
        $workspace = $this->workspaces->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        $workspace->mutationLock?->release();
        $this->journal->save($this->failedAttempt());

        (new RunnerControlService($this->source))->cleanup('TASK');

        self::assertNull($this->journal->load('TASK'));
        self::assertDirectoryDoesNotExist($workspace->lease->path);
    }

    public function testDirtyFailedProviderAttemptRemainsFailClosed(): void
    {
        $workspace = $this->workspaces->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        file_put_contents($workspace->lease->path . '/candidate.txt', 'preserve me');
        $workspace->mutationLock?->release();
        $this->journal->save($this->failedAttempt());

        try {
            (new RunnerControlService($this->source))->cleanup('TASK');
            self::fail('Expected dirty failed-provider cleanup refusal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Refusing to remove dirty Run workspace', $exception->getMessage());
        }

        self::assertNotNull($this->journal->load('TASK'));
        self::assertFileExists($workspace->lease->path . '/candidate.txt');
    }

    public function testParsedProcessExitRemainsUnreconciledEvidence(): void
    {
        $workspace = $this->workspaces->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        $workspace->mutationLock?->release();
        $this->journal->save(new RuntimeAttempt(
            'TASK',
            'RUN',
            1,
            'sha256:plan',
            'builder',
            1,
            'claude',
            hash('sha256', $workspace->lease->path),
            'submission-1',
            AttemptStatus::ProcessExited,
            null,
            null,
            [
                'started_at' => '2026-09-13T04:00:00+00:00',
                'exited_at' => '2026-09-13T04:01:00+00:00',
                'exit_code' => 0,
                'timed_out' => false,
            ],
            '',
            [
                'outcome' => 'pass',
                'summary' => 'Provider returned a parsed completion envelope.',
                'artifact_references' => [],
                'validation_references' => [],
            ],
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workspace has unreconciled evidence');
        try {
            (new RunnerControlService($this->source))->cleanup('TASK');
        } finally {
            self::assertNotNull($this->journal->load('TASK'));
            self::assertDirectoryExists($workspace->lease->path);
        }
    }

    private function failedAttempt(): RuntimeAttempt
    {
        return new RuntimeAttempt(
            'TASK',
            'RUN',
            1,
            'sha256:plan',
            'builder',
            1,
            'claude',
            'workspace-identity',
            'submission-1',
            AttemptStatus::ProcessExited,
            null,
            null,
            [
                'started_at' => '2026-09-13T04:00:00+00:00',
                'exited_at' => '2026-09-13T04:01:00+00:00',
                'exit_code' => 1,
                'timed_out' => false,
            ],
        );
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $command = ['git', '-C', $cwd, ...$args];
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);

        return (string) $stdout;
    }
}
