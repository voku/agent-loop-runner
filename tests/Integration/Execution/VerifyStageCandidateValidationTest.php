<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Execution;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\CompileResult;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
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

/**
 * The deterministic verify stage validates the governed candidate in the Run worktree, so a build that breaks the
 * Contract's declared validation cannot complete the plan (voku/agent-loop#799).
 */
final class VerifyStageCandidateValidationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-verify-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php final class Foo {}\n");
        foreach ([['init', '-q'], ['config', 'user.email', 'x@y.test'], ['config', 'user.name', 'X'], ['add', '.'], ['commit', '-qm', 'base']] as $a) {
            exec('git -C ' . escapeshellarg($this->root) . ' ' . implode(' ', array_map('escapeshellarg', $a)));
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testCandidateThatFailsDeclaredValidationStopsAtAttentionInsteadOfCompleting(): void
    {
        $base = trim((string) shell_exec('git -C ' . escapeshellarg($this->root) . ' rev-parse HEAD'));
        ob_start();
        (new WorkflowPlanCommand($this->root))->run(['TASK-1', '--by', 'owner', '--file', 'src/Foo.php', '--goal', 'g', '--validation', 'php -l src/Foo.php', '--base-commit', $base]);
        (new WorkflowApproveCommand($this->root))->run([
            'TASK-1',
            '--revision', (string) (new TaskContractStore($this->root))->load('TASK-1')->revision,
            '--by', 'owner',
        ]);
        (new WorkflowExecutionProfileCommand($this->root))->run(['TASK-1', '--profile', 'surgical', '--by', 'owner']);
        (new HostFrontDoorCommand($this->root, static function (CompileRequest $request): CompileResult {
            mkdir($request->outputDirectory, 0o775, true);
            file_put_contents($request->outputDirectory . '/meta.json', json_encode(['schema_version' => '1.0', 'task_id' => 'TASK-1', 'compilation_id' => 'fixture', 'selected_guidance' => [], 'selected_constraints' => [], 'output_hashes' => []], JSON_THROW_ON_ERROR));
            file_put_contents($request->outputDirectory . '/system.md', "# Recall\n");

            return new CompileResult($request->outputDirectory, 'fixture', str_repeat('a', 64));
        }))->run('enter', ['TASK-1', '--format=json']);
        ob_end_clean();

        $host = new BrokenBuilderHost();
        $layout = new RunnerLayout($this->root);
        $supervisor = new ForegroundProcessSupervisor();
        $git = new GitCommand($supervisor, ['PATH' => (string) getenv('PATH')]);
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

        try {
            $coordinator->run('TASK-1');
            self::fail('A candidate that fails its declared validation must not complete the plan.');
        } catch (\RuntimeException $exception) {
            self::assertStringStartsWith('WAITING_FOR_ATTENTION', $exception->getMessage());
        }

        $projection = (new ExecutionGateway($this->root))->projection('TASK-1');
        self::assertFalse($projection->complete());
        self::assertSame('verify', $projection->currentStageId);
        self::assertNotNull($projection->attention);
        self::assertStringContainsString('[FAIL] php -l src/Foo.php', $projection->attention->message);
        self::assertSame(3, $host->executions, 'Only the three agent stages ran; verify is Loop-owned and deterministic.');
        self::assertSame("<?php final class Foo {}\n", file_get_contents($this->root . '/src/Foo.php'), 'The user checkout is never validated or mutated.');
    }
}

final class BrokenBuilderHost implements HostAdapter
{
    public int $executions = 0;

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
        $request->observer->started(40_000 + $this->executions, '2026-01-01T00:00:00+00:00');
        $artifacts = [];
        if ($request->roleId === 'builder') {
            file_put_contents($request->workingDirectory . '/src/Foo.php', "<?php final class Foo { this is not php }\n");
            $artifacts[] = 'src/Foo.php';
        } elseif ($request->roleId === 'reviewer') {
            // The reviewer must cite the changed file before Loop can evaluate deterministic validation.
            $artifacts[] = 'src/Foo.php';
        }
        $stdout = 'AGENT_LOOP_STAGE_RESULT ' . json_encode([
            'outcome' => $request->roleId === 'reviewer' ? 'pass' : 'completed',
            'summary' => 'fixture ' . $request->roleId,
            'artifact_references' => $artifacts,
            'validation_references' => [],
        ], JSON_THROW_ON_ERROR) . "\n";

        return new HostExecutionResult('fake', new ProcessResult(0, $stdout, '', false, '2026-01-01T00:00:00+00:00', '2026-01-01T00:01:00+00:00'));
    }
}
