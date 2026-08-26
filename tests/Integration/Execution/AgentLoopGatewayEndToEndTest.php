<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Diagnostics\DiagnosticLogStore;
use voku\AgentLoopRunner\Execution\AgentLoopExecutionGateway;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionCoordinator;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\HostAvailability;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Host\HostExecutionResult;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\ProcessResult;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

final class AgentLoopGatewayEndToEndTest extends TestCase
{
    private string $root;
    private string $base;
    private string $originalSource;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-real-gateway-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        $this->originalSource = '<?php final class Foo {}';
        file_put_contents($this->root . '/src/Foo.php', $this->originalSource);
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

    /** @return iterable<string, array{non-empty-string, int}> */
    public static function profiles(): iterable
    {
        yield 'surgical' => ['surgical', 3];
        yield 'standard' => ['standard', 4];
        yield 'hardened' => ['hardened', 7];
    }

    #[DataProvider('profiles')]
    public function testProfilesUsePublicTypedGatewayEndToEnd(string $profile, int $agentStages): void
    {
        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'TASK-1',
            '--by', 'owner',
            '--file', 'src/Foo.php',
            '--goal', 'Prove runner orchestration.',
            '--validation', 'composer ci',
            '--base-commit', $this->base,
        ]));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['TASK-1', '--by', 'owner']));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            'TASK-1',
            '--profile', $profile,
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

        $layout = new RunnerLayout($this->root);
        $supervisor = new ForegroundProcessSupervisor();
        $git = new GitCommand($supervisor, ['PATH' => (string) getenv('PATH')]);
        $host = new OutcomeHost();
        $coordinator = new ExecutionCoordinator(
            new AgentLoopExecutionGateway(new ExecutionGateway($this->root)),
            new RuntimeJournal($layout),
            new RunWorkspaceManager($layout, new GitWorktreeService($git), new WorkspaceCandidateHasher($git)),
            new CompletionEnvelopeParser(),
            RunnerConfig::defaults(),
            ['codex' => $host, 'claude' => $host],
            $supervisor,
            new DiagnosticLogStore($layout),
        );

        $projection = $coordinator->run('TASK-1');
        self::assertTrue($projection->complete());
        self::assertSame($agentStages, $host->executions);
        self::assertSame($agentStages, $host->environmentBoundExecutions);
        self::assertSame($profile, $projection->profile->value);
        self::assertMatchesRegularExpression('/^git-tree-v1:' . preg_quote($this->base, '/') . ':[0-9a-f]{40,64}$/', $projection->candidateRevision);
        self::assertSame($this->originalSource, file_get_contents($this->root . '/src/Foo.php'), 'Runner work must not mutate the user checkout.');
        self::assertSame('', $this->git(['diff', '--cached', '--name-only']), 'Runner work must not mutate the user index.');
        self::assertSame('', $this->git(['status', '--porcelain', '--untracked-files=no']), 'Tracked user checkout state must remain clean.');
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
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);

        return trim((string) $stdout);
    }
}

final class OutcomeHost implements HostAdapter
{
    public int $executions = 0;
    public int $environmentBoundExecutions = 0;

    public function id(): string
    {
        return 'fake';
    }

    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability
    {
        return new HostAvailability('fake', 'fake', '1', null);
    }

    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        ++$this->executions;
        if (str_contains($request->prompt, '# Current bounded execution environment')
            && str_contains($request->prompt, 'Observation digest: sha256:')) {
            ++$this->environmentBoundExecutions;
        }
        $artifactReferences = [];
        if ($request->roleId === 'builder') {
            file_put_contents(
                $request->workingDirectory . '/src/Foo.php',
                "<?php\nfinal class Foo { public const string BUILT = 'runner'; }\n",
            );
            $artifactReferences[] = 'src/Foo.php';
        }
        $outcome = in_array($request->roleId, ['investigator', 'builder', 'hardening'], true)
            ? 'completed'
            : 'pass';
        $stdout = 'AGENT_LOOP_STAGE_RESULT ' . json_encode([
            'outcome' => $outcome,
            'summary' => 'fixture ' . $request->roleId,
            'artifact_references' => $artifactReferences,
            'validation_references' => [],
        ], JSON_THROW_ON_ERROR) . "\n";

        return new HostExecutionResult(
            'fake',
            new ProcessResult(0, $stdout, '', false, 'start', 'finish'),
        );
    }
}
