<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\CompileResult;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentLoopRunner\Application\RunnerControlService;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;

final class RunnerControlServiceStatusTest extends TestCase
{
    private string $root;
    private string $base;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-status-test-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', '<?php final class Foo {}');

        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'x@y.test']);
        $this->git(['config', 'user.name', 'X']);
        $this->git(['add', '.']);
        $this->git(['commit', '-qm', 'base']);
        $this->base = trim($this->git(['rev-parse', 'HEAD']));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testStatusProjectsRequiredHostForCurrentStage(): void
    {
        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'TASK-1',
            '--by', 'owner',
            '--file', 'src/Foo.php',
            '--goal', 'Prove runner status preflight.',
            '--validation', 'composer ci',
            '--base-commit', $this->base,
        ]));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run([
            'TASK-1',
            '--revision', (string) (new TaskContractStore($this->root))->load('TASK-1')->revision,
            '--by', 'owner',
        ]));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            'TASK-1',
            '--profile', 'surgical',
            '--by', 'owner',
        ]));
        ob_end_clean();

        ob_start();
        $exit = (new HostFrontDoorCommand(
            $this->root,
            function (CompileRequest $request): CompileResult {
                $directory = $request->outputDirectory;
                mkdir($directory, 0o775, true);
                file_put_contents($directory . '/meta.json', json_encode([
                    'schema_version' => '1.0',
                    'task_id' => 'TASK-1',
                    'compilation_id' => 'fixture',
                    'selected_guidance' => [],
                    'selected_constraints' => [],
                    'output_hashes' => [],
                ], JSON_THROW_ON_ERROR));
                file_put_contents($directory . '/system.md', "# Recall\nStay governed.\n");

                return new CompileResult($directory, 'fixture', str_repeat('a', 64));
            },
        ))->run('enter', ['TASK-1', '--format=json']);
        ob_end_clean();
        self::assertSame(0, $exit);

        $planPath = (new ExecutionPlanStore($this->root))->path('TASK-1');
        $statePath = (new ExecutionStateStore($this->root))->path('TASK-1');
        if (is_file($statePath)) {
            self::assertTrue(unlink($statePath));
        }
        if (is_file($planPath)) {
            self::assertTrue(unlink($planPath));
        }

        $service = new RunnerControlService($this->root);
        self::assertNull($service->observedAttempt('TASK-1'));
        self::assertFileDoesNotExist($planPath);
        self::assertFileDoesNotExist($statePath);

        // The explicit status/control API may still prepare authority when
        // used for an actual control, unlike the read-only observation API.
        $status = $service->status('TASK-1');

        self::assertSame('TASK-1', $status->authority->taskId);
        self::assertSame('investigate', $status->authority->currentStageId);
        self::assertNotNull($status->requiredHost);
        self::assertSame('investigator', $status->requiredHost->roleId);
        self::assertSame('codex', $status->requiredHost->hostId);

        $serialized = $status->toArray();
        self::assertNotNull($serialized['required_host']);
        self::assertSame('investigator', $serialized['required_host']['role_id']);
        self::assertSame('codex', $serialized['required_host']['host_id']);
    }

    public function testObservedAttemptReturnsJournalWithoutAskingExecutionAuthority(): void
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->root));
        $attempt = new RuntimeAttempt(
            'TASK-1',
            'run-1',
            1,
            'sha256:' . str_repeat('a', 64),
            'build',
            1,
            'codex',
            'workspace-1',
            'submission-1',
            updatedAt: '2026-11-13T20:00:00Z',
        );
        $journal->save($attempt);

        $observed = (new RunnerControlService($this->root))->observedAttempt('TASK-1');
        self::assertNotNull($observed);
        self::assertSame($attempt->toArray(), $observed->toArray());
        self::assertFileDoesNotExist((new ExecutionPlanStore($this->root))->path('TASK-1'));
        self::assertFileDoesNotExist((new ExecutionStateStore($this->root))->path('TASK-1'));
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = proc_open(
            ['git', '-C', $this->root, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return (string) $stdout;
    }
}
