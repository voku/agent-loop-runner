<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Workspace;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

final class RunWorkspaceManagerTest extends TestCase
{
    private string $root;
    private string $source;
    private string $base;
    private RunWorkspaceManager $manager;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-workspace-' . bin2hex(random_bytes(5));
        $this->source = $this->root . '/source repo';
        mkdir($this->source, 0o700, true);
        $this->git($this->source, ['init', '-q']);
        $this->git($this->source, ['config', 'user.email', 'test@example.test']);
        $this->git($this->source, ['config', 'user.name', 'Test']);
        file_put_contents($this->source . '/tracked.txt', "base\n");
        file_put_contents($this->source . '/mode.sh', "#!/bin/sh\necho base\n");
        chmod($this->source . '/mode.sh', 0o644);
        $this->git($this->source, ['add', '.']);
        $this->git($this->source, ['commit', '-qm', 'base']);
        $this->base = trim($this->git($this->source, ['rev-parse', 'HEAD']));
        $command = new GitCommand(new ForegroundProcessSupervisor(), ['PATH' => (string) getenv('PATH')]);
        $this->manager = new RunWorkspaceManager(
            new RunnerLayout($this->source),
            new GitWorktreeService($command),
            new WorkspaceCandidateHasher($command),
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testReusesOneRunWorkspaceAndLeavesDirtySourceUntouched(): void
    {
        file_put_contents($this->source . '/source-only.txt', 'dirty');
        $first = $this->manager->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        file_put_contents($first->lease->path . '/odd name' . "\t" . '.txt', 'candidate');
        $candidate = $this->manager->candidateAfter($first);
        $first->mutationLock?->release();
        $second = $this->manager->acquire('TASK', 'RUN', $this->base, 'reviewer', 1, false, $candidate);

        self::assertSame($first->lease->path, $second->lease->path);
        self::assertFileExists($this->source . '/source-only.txt');
        self::assertSame("?? source-only.txt\0", $this->git($this->source, ['status', '--porcelain=v1', '-z']));
    }

    public function testCandidateTreeCapturesRepositoryStateWithoutMutatingEitherIndex(): void
    {
        $workspace = $this->manager->acquire('TASK', 'RUN-TREE', $this->base, 'builder', 1, true, $this->base);
        $path = $workspace->lease->path;
        $sourceIndexBefore = $this->git($this->source, ['diff', '--cached', '--name-only', '-z']);
        $worktreeIndexBefore = $this->git($path, ['diff', '--cached', '--name-only', '-z']);

        file_put_contents($path . '/tracked.txt', "changed\n");
        unlink($path . '/mode.sh');
        file_put_contents($path . '/binary.bin', "\x00\x01\xffbinary\x00");
        file_put_contents($path . '/odd name' . "\t" . '.txt', "odd\n");
        file_put_contents($path . '/executable.sh', "#!/bin/sh\necho candidate\n");
        chmod($path . '/executable.sh', 0o755);
        $symlinkCreated = symlink('tracked.txt', $path . '/tracked-link');

        $first = $this->manager->candidateAfter($workspace);
        $second = $this->manager->candidateAfter($workspace);
        self::assertSame($first, $second, 'Identical workspace state must produce an identical Git tree candidate.');
        self::assertMatchesRegularExpression('/^git-tree-v1:' . preg_quote($this->base, '/') . ':[0-9a-f]{40,64}$/', $first);
        $tree = substr($first, strrpos($first, ':') + 1);
        self::assertSame("tree\n", $this->git($path, ['cat-file', '-t', $tree]));
        self::assertSame($this->base . "\n", $this->git($path, ['rev-parse', 'HEAD']));
        self::assertSame($sourceIndexBefore, $this->git($this->source, ['diff', '--cached', '--name-only', '-z']));
        self::assertSame($worktreeIndexBefore, $this->git($path, ['diff', '--cached', '--name-only', '-z']));
        self::assertFileExists($path . '/tracked.txt');
        self::assertFileDoesNotExist($path . '/mode.sh');
        if ($symlinkCreated) {
            self::assertTrue(is_link($path . '/tracked-link'));
        }

        file_put_contents($path . '/tracked.txt', "changed again\n");
        $third = $this->manager->candidateAfter($workspace);
        self::assertNotSame($first, $third, 'A changed workspace must produce a different candidate tree.');
        self::assertSame($sourceIndexBefore, $this->git($this->source, ['diff', '--cached', '--name-only', '-z']));
        self::assertSame($worktreeIndexBefore, $this->git($path, ['diff', '--cached', '--name-only', '-z']));
        $workspace->mutationLock?->release();
    }

    public function testMutatingLeaseIsExclusiveAndStaleLeaseFailsClosed(): void
    {
        $owned = $this->manager->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        try {
            $this->manager->acquire('TASK', 'RUN', $this->base, 'hardening', 1, true, $this->base);
            self::fail('Expected exclusive lease failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('another mutating stage', $exception->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->manager->assertLease($owned->lease, 'TASK', 'OTHER-RUN', 'builder', 1, true);
    }

    public function testReadOnlyMutationIsDetectedWithoutDestroyingEvidence(): void
    {
        $workspace = $this->manager->acquire('TASK', 'RUN', $this->base, 'reviewer', 1, false, $this->base);
        file_put_contents($workspace->lease->path . '/tracked.txt', 'mutated');
        try {
            $this->manager->candidateAfter($workspace);
            self::fail('Expected read-only mutation failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('read-only stage modified', $exception->getMessage());
        }
        self::assertSame('mutated', file_get_contents($workspace->lease->path . '/tracked.txt'));
    }

    public function testLargeUntrackedCandidateUsesGitStreamingAndStableTreeIdentity(): void
    {
        $workspace = $this->manager->acquire('TASK', 'RUN-LARGE', $this->base, 'builder', 1, true, $this->base);
        $handle = fopen($workspace->lease->path . '/large.bin', 'wb');
        self::assertIsResource($handle);
        for ($i = 0; $i < 8; ++$i) {
            self::assertSame(1024 * 1024, fwrite($handle, str_repeat(chr(65 + $i), 1024 * 1024)));
        }
        fclose($handle);

        $candidate = $this->manager->candidateAfter($workspace);
        $repeated = $this->manager->candidateAfter($workspace);
        $workspace->mutationLock?->release();

        self::assertSame($candidate, $repeated);
        self::assertMatchesRegularExpression('/^git-tree-v1:[0-9a-f]{40,64}:[0-9a-f]{40,64}$/', $candidate);
    }

    public function testResumeAfterProcessRequiresExistingWorkspaceAndObservedCandidate(): void
    {
        $workspace = $this->manager->acquire('TASK', 'RUN-RESUME', $this->base, 'builder', 1, true, $this->base);
        file_put_contents($workspace->lease->path . '/candidate.txt', 'one');
        $candidate = $this->manager->candidateAfter($workspace);
        $workspace->mutationLock?->release();

        $resumed = $this->manager->resumeAfterProcess('TASK', 'RUN-RESUME', $this->base, 'builder', 1, true, $this->base, $candidate);
        self::assertSame($candidate, $this->manager->candidateAfter($resumed));
        $resumed->mutationLock?->release();

        file_put_contents($workspace->lease->path . '/candidate.txt', 'two');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed after candidate observation');
        $this->manager->resumeAfterProcess('TASK', 'RUN-RESUME', $this->base, 'builder', 1, true, $this->base, $candidate);
    }

    public function testDirtyCleanupRefusesAndUnrelatedWorktreeSurvives(): void
    {
        $workspace = $this->manager->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        $unrelated = $this->root . '/unrelated';
        $this->git($this->source, ['worktree', 'add', '--detach', $unrelated, $this->base]);
        file_put_contents($workspace->lease->path . '/candidate.txt', 'keep');
        try {
            $this->manager->cleanup('TASK', 'RUN');
            self::fail('Expected dirty cleanup refusal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Refusing to remove dirty', $exception->getMessage());
        }
        self::assertDirectoryExists($unrelated);
        self::assertFileExists($workspace->lease->path . '/candidate.txt');
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
