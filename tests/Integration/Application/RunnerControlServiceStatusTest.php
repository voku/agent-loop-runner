<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentLoopRunner\Application\RunnerControlService;

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
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['TASK-1', '--by', 'owner']));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            'TASK-1',
            '--profile', 'surgical',
            '--by', 'owner',
        ]));
        ob_end_clean();

        ob_start();
        $exit = (new HostFrontDoorCommand(
            $this->root,
            function (array $argv): int {
                $directory = $this->root . '/.agent-loop/recall/TASK-1';
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

                return 0;
            },
        ))->run('enter', ['TASK-1', '--format=json']);
        ob_end_clean();
        self::assertSame(0, $exit);

        $service = new RunnerControlService($this->root);
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
