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
        $this->source = $this->root . '/source repo'; mkdir($this->source, 0o700, true);
        $this->git($this->source, ['init', '-q']); $this->git($this->source, ['config', 'user.email', 'test@example.test']); $this->git($this->source, ['config', 'user.name', 'Test']);
        file_put_contents($this->source . '/tracked.txt', "base\n"); $this->git($this->source, ['add', '.']); $this->git($this->source, ['commit', '-qm', 'base']);
        $this->base = trim($this->git($this->source, ['rev-parse', 'HEAD']));
        $command = new GitCommand(new ForegroundProcessSupervisor(), ['PATH' => (string) getenv('PATH')]);
        $this->manager = new RunWorkspaceManager(new RunnerLayout($this->source), new GitWorktreeService($command), new WorkspaceCandidateHasher($command));
    }
    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->root)); }

    public function testReusesOneRunWorkspaceAndLeavesDirtySourceUntouched(): void
    {
        file_put_contents($this->source . '/source-only.txt', 'dirty');
        $first = $this->manager->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        file_put_contents($first->lease->path . '/odd name' . "\t" . '.txt', 'candidate');
        $candidate = $this->manager->candidateAfter($first); $first->mutationLock?->release();
        $second = $this->manager->acquire('TASK', 'RUN', $this->base, 'reviewer', 1, false, $candidate);
        self::assertSame($first->lease->path, $second->lease->path);
        self::assertFileExists($this->source . '/source-only.txt');
        self::assertSame("?? source-only.txt\0", $this->git($this->source, ['status', '--porcelain=v1', '-z']));
    }

    public function testMutatingLeaseIsExclusiveAndStaleLeaseFailsClosed(): void
    {
        $owned = $this->manager->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        try { $this->manager->acquire('TASK', 'RUN', $this->base, 'hardening', 1, true, $this->base); self::fail('Expected exclusive lease failure.'); }
        catch (RuntimeException $e) { self::assertStringContainsString('another mutating stage', $e->getMessage()); }
        $this->expectException(RuntimeException::class);
        $this->manager->assertLease($owned->lease, 'TASK', 'OTHER-RUN', 'builder', 1, true);
    }

    public function testReadOnlyMutationIsDetectedWithoutDestroyingEvidence(): void
    {
        $workspace = $this->manager->acquire('TASK', 'RUN', $this->base, 'reviewer', 1, false, $this->base);
        file_put_contents($workspace->lease->path . '/tracked.txt', 'mutated');
        try { $this->manager->candidateAfter($workspace); self::fail('Expected read-only mutation failure.'); }
        catch (RuntimeException $e) { self::assertStringContainsString('read-only stage modified', $e->getMessage()); }
        self::assertSame('mutated', file_get_contents($workspace->lease->path . '/tracked.txt'));
    }

    public function testDirtyCleanupRefusesAndUnrelatedWorktreeSurvives(): void
    {
        $workspace = $this->manager->acquire('TASK', 'RUN', $this->base, 'builder', 1, true, $this->base);
        $unrelated = $this->root . '/unrelated'; $this->git($this->source, ['worktree', 'add', '--detach', $unrelated, $this->base]);
        file_put_contents($workspace->lease->path . '/candidate.txt', 'keep');
        try { $this->manager->cleanup('TASK', 'RUN'); self::fail('Expected dirty cleanup refusal.'); }
        catch (RuntimeException $e) { self::assertStringContainsString('Refusing to remove dirty', $e->getMessage()); }
        self::assertDirectoryExists($unrelated); self::assertFileExists($workspace->lease->path . '/candidate.txt');
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $command = array_merge(['git', '-C', $cwd], $args); $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($process); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $err); return (string) $out;
    }
}
