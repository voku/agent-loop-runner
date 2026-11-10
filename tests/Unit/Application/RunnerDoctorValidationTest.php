<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\CompileResult;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentLoopRunner\Application\RunnerControlService;

final class RunnerDoctorValidationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-doctor-validation-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/.agent-loop-runner', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', '<?php final class Foo {}');
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'x@y.test']);
        $this->git(['config', 'user.name', 'X']);
        $this->git(['add', '.']);
        $this->git(['commit', '-qm', 'base']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testDoctorWithoutTaskHasNoValidationKey(): void
    {
        [$exit, $data] = $this->doctor([]);

        self::assertSame(0, $exit);
        self::assertArrayNotHasKey('validation', $data);
    }

    public function testDoctorTaskReportsValidationAndIsReadOnly(): void
    {
        $this->plan(['php -v', 'php vendor/bin/phpunit tests', "php -r 'echo 1;'"]);

        $statusBefore = (new RunnerControlService($this->root))->status('TASK-1');
        self::assertNull($statusBefore->observation?->stageResult);
        $projectionBefore = $this->projection();
        $treeBefore = $this->tree();
        [$exitWithout] = $this->doctor([]);
        [$exit, $data] = $this->doctor(['TASK-1']);

        $statusAfter = (new RunnerControlService($this->root))->status('TASK-1');
        self::assertNull($statusAfter->observation?->stageResult, 'doctor TASK must not persist a stage result.');
        self::assertSame($statusBefore->authority->currentStageId, $statusAfter->authority->currentStageId);
        self::assertSame($statusBefore->authority->currentAttempt, $statusAfter->authority->currentAttempt);
        self::assertSame($exitWithout, $exit);
        self::assertSame($projectionBefore, $this->projection());
        self::assertSame($treeBefore, $this->tree());

        $validation = $data['validation'] ?? null;
        self::assertIsArray($validation);
        self::assertIsString($validation['stage'] ?? null);
        $note = $validation['note'] ?? null;
        self::assertIsString($note);
        self::assertStringContainsString('not checked', $note);
        $commands = $validation['commands'] ?? null;
        self::assertIsArray($commands);
        $byCommand = [];
        foreach ($commands as $entry) {
            self::assertIsArray($entry);
            $name = $entry['command'] ?? null;
            self::assertIsString($name);
            $byCommand[$name] = $entry;
        }
        self::assertSame('ok', $byCommand['php -v']['status'] ?? null);
        self::assertSame('missing_worktree_dependency', $byCommand['php vendor/bin/phpunit tests']['status'] ?? null);
        self::assertSame('vendor/bin/phpunit', $byCommand['php vendor/bin/phpunit tests']['path'] ?? null);
        self::assertSame('not_inspectable', $byCommand["php -r 'echo 1;'"]['status'] ?? null);
    }

    public function testUnknownTaskStillCarriesNoteAndKeepsExitCode(): void
    {
        [$exitWithout] = $this->doctor([]);
        [$exit, $data] = $this->doctor(['NOPE']);

        self::assertSame($exitWithout, $exit);
        $validation = $data['validation'] ?? null;
        self::assertIsArray($validation);
        self::assertArrayHasKey('reason', $validation);
        $note = $validation['note'] ?? null;
        self::assertIsString($note);
        self::assertStringContainsString('not checked', $note);
    }

    /** @param list<string> $validation */
    private function plan(array $validation): void
    {
        $base = trim($this->git(['rev-parse', 'HEAD']));
        $arguments = ['TASK-1', '--by', 'owner', '--file', 'src/Foo.php', '--goal', 'Doctor validation.', '--base-commit', $base];
        foreach ($validation as $command) {
            array_push($arguments, '--validation', $command);
        }
        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run($arguments));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run([
            'TASK-1',
            '--revision', (string) (new TaskContractStore($this->root))->load('TASK-1')->revision,
            '--by', 'owner',
        ]));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run(['TASK-1', '--profile', 'surgical', '--by', 'owner']));
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
    }

    private function projection(): string
    {
        return json_encode((new RunnerControlService($this->root))->status('TASK-1')->toArray(), JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function tree(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && !str_contains($file->getPathname(), '/.git/')) {
                $files[$file->getPathname()] = (string) hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param list<string> $arguments
     * @return array{int, array<string, mixed>}
     */
    private function doctor(array $arguments): array
    {
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($loader);
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                'require $argv[1]; spl_autoload_register(static function (string $c) use ($argv): void {'
                . ' $p = "voku\\\\AgentLoopRunner\\\\";'
                . ' if (str_starts_with($c, $p) && is_file($f = $argv[2] . "/" . str_replace("\\\\", "/", substr($c, strlen($p))) . ".php")) { require $f; }'
                . ' }); exit((new voku\AgentLoopRunner\Application\RunnerApplication(getcwd()))->run(["doctor", ...array_slice($argv, 3)]));',
                dirname($loader, 2) . '/autoload.php',
                dirname(__DIR__, 3) . '/src',
                ...$arguments,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $exit = proc_close($process);
        self::assertJson($stdout, 'stdout: ' . $stdout . ' stderr: ' . $stderr);
        $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        /** @var array<string, mixed> $data */

        return [$exit, $data];
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
