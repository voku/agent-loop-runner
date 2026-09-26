<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\CompileResult;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Workflow\ExecutionContractStore;
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
use voku\AgentLoopRunner\Host\CodexHostAdapter;
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

        $host = new OutcomeHost();
        $projection = $this->coordinator($host)->run('TASK-1');
        self::assertTrue($projection->complete());
        self::assertSame($agentStages, $host->executions);
        self::assertSame($agentStages, $host->environmentBoundExecutions);
        self::assertSame($profile, $projection->profile->value);
        self::assertMatchesRegularExpression('/^git-tree-v1:' . preg_quote($this->base, '/') . ':[0-9a-f]{40,64}$/', $projection->candidateRevision);
        self::assertSame($this->originalSource, file_get_contents($this->root . '/src/Foo.php'), 'Runner work must not mutate the user checkout.');
        self::assertSame('', $this->git(['diff', '--cached', '--name-only']), 'Runner work must not mutate the user index.');
        self::assertSame('', $this->git(['status', '--porcelain', '--untracked-files=no']), 'Tracked user checkout state must remain clean.');
    }

    public function testRealCodexAdapterProducesFreshProcessContextEvidenceWithoutCredentials(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The local Codex fixture uses a POSIX shell executable.');
        }

        $taskId = 'TASK-CODEX-CONTEXT';
        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            $taskId,
            '--by', 'owner',
            '--file', 'src/Foo.php',
            '--goal', 'Prove fresh context through the real Codex process adapter.',
            '--validation', 'composer ci',
            '--base-commit', $this->base,
        ]));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run([$taskId, '--by', 'owner']));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            $taskId,
            '--profile', 'surgical',
            '--by', 'owner',
        ]));
        ob_end_clean();

        ob_start();
        $exit = (new HostFrontDoorCommand(
            $this->root,
            function (CompileRequest $request) use ($taskId): CompileResult {
                $directory = $request->outputDirectory;
                mkdir($directory, 0o775, true);
                file_put_contents($directory . '/meta.json', json_encode([
                    'schema_version' => '1.0',
                    'task_id' => $taskId,
                    'compilation_id' => 'real-codex-process-fixture',
                    'selected_guidance' => [],
                    'selected_constraints' => [],
                    'output_hashes' => [],
                ], JSON_THROW_ON_ERROR));
                file_put_contents($directory . '/system.md', "# Recall\nStay governed.\n");

                return new CompileResult($directory, 'real-codex-process-fixture', str_repeat('a', 64));
            },
        ))->run('enter', [$taskId, '--format=json']);
        ob_end_clean();
        self::assertSame(0, $exit);

        $fakeCodex = $this->fakeCodexBinary();
        $config = new RunnerConfig(
            ['codex' => ['binary' => $fakeCodex]],
            [
                'investigator' => 'codex',
                'builder' => 'codex',
                'reviewer' => 'codex',
            ],
            60,
            ['PATH'],
        );
        $host = new CodexHostAdapter($fakeCodex);

        $projection = $this->coordinatorWithHost($host, $config)->run($taskId);
        self::assertTrue($projection->complete());

        $state = (new ExecutionStateStore($this->root))->find($taskId);
        self::assertNotNull($state);
        $contexts = [];
        foreach ($state->history as $accepted) {
            if ($accepted->result->contextId !== null) {
                $contexts[$accepted->result->stageId] = $accepted->result->contextId;
            }
        }

        self::assertArrayHasKey('build', $contexts);
        self::assertArrayHasKey('review', $contexts);
        self::assertStringStartsWith('runner-context:sha256:', $contexts['build']);
        self::assertStringStartsWith('runner-context:sha256:', $contexts['review']);
        self::assertNotSame($contexts['build'], $contexts['review']);

        $runtime = (new RuntimeJournal(new RunnerLayout($this->root)))->load($taskId);
        self::assertNotNull($runtime);
        self::assertSame('review', $runtime->stageId);
        self::assertIsInt($runtime->process['pid'] ?? null);
        self::assertIsString($runtime->process['started_at'] ?? null);
        if (PHP_OS_FAMILY === 'Linux') {
            self::assertIsString($runtime->process['process_fingerprint'] ?? null);
        }

        self::assertSame($this->originalSource, file_get_contents($this->root . '/src/Foo.php'));
        self::assertSame('', $this->git(['diff', '--cached', '--name-only']));
        self::assertSame('', $this->git(['status', '--porcelain', '--untracked-files=no']));
    }

    public function testL2ExecutionContractReachesHostInsteadOfConstructionBriefing(): void
    {
        file_put_contents($this->root . '/operating-prompts.json', json_encode([
            'schema_version' => '1.0',
            'prompts' => [[
                'id' => 'test-l2',
                'level' => 2,
                'template' => 'Create a project-specific L1 execution contract.',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'TASK-L2',
            '--by', 'owner',
            '--file', 'src/Foo.php',
            '--goal', 'Prove the runner receives the final governed L1.',
            '--validation', 'composer ci',
            '--base-commit', $this->base,
            '--operating-prompt-manifest', 'operating-prompts.json',
            '--operating-prompt', '{"id":"test-l2","arguments":{}}',
        ]));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['TASK-L2', '--by', 'owner']));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            'TASK-L2',
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
                    'task_id' => 'TASK-L2',
                    'compilation_id' => 'fixture-l2',
                    'selected_guidance' => [],
                    'selected_constraints' => [],
                    'output_hashes' => [],
                ], JSON_THROW_ON_ERROR));
                file_put_contents(
                    $directory . '/system.md',
                    "# Recall\n## L2 Operational Prompt Construction\nCreate a project-specific L1 execution contract.\n",
                );
                file_put_contents($directory . '/facts.json', json_encode([
                    'schema_version' => '1.0',
                    'bundle_sha256' => str_repeat('a', 64),
                    'facts' => [[
                        'id' => 'operating-prompt.test-l2',
                        'type' => 'operating_prompt',
                        'authority' => 'approved_contract',
                        'source_ref' => 'operating-prompts.json#test-l2',
                        'scope' => ['src/Foo.php'],
                        'payload' => [
                            'prompt_id' => 'test-l2',
                            'level' => 2,
                            'arguments' => [],
                            'content' => 'Create a project-specific L1 execution contract.',
                            'template_sha256' => str_repeat('c', 64),
                        ],
                    ]],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return new CompileResult($directory, 'fixture-l2', str_repeat('a', 64));
            },
        ))->run('enter', ['TASK-L2', '--format=json']);
        ob_end_clean();
        self::assertSame(1, $exit, 'L2 enter must remain blocked until the concrete L1 exists.');

        (new ExecutionContractStore($this->root))->writeReady('TASK-L2', 'constructor', <<<'MD'
## Goal
Execute the approved runner integration proof.

## Context
The acting host must consume this exact final L1, not the construction briefing.

## Constraints
Stay inside the approved task scope and preserve Loop authority.

## Verification
Run the existing Runner integration and static-analysis suites.

## Done When
Every acting host prompt contains this final contract and no L2 construction instruction.
MD);

        $host = new OutcomeHost();
        $projection = $this->coordinator($host)->run('TASK-L2');

        self::assertTrue($projection->complete());
        self::assertSame(3, $host->executions);
        self::assertCount(3, $host->prompts);
        foreach ($host->prompts as $prompt) {
            self::assertStringContainsString('# Governed execution contract', $prompt);
            self::assertStringContainsString('The acting host must consume this exact final L1', $prompt);
            self::assertStringNotContainsString('# Governed Recall', $prompt);
            self::assertStringNotContainsString('L2 Operational Prompt Construction', $prompt);
            self::assertStringNotContainsString('Create a project-specific L1 execution contract.', $prompt);
        }
    }

    private function coordinatorWithHost(HostAdapter $host, RunnerConfig $config): ExecutionCoordinator
    {
        $layout = new RunnerLayout($this->root);
        $supervisor = new ForegroundProcessSupervisor();
        $git = new GitCommand($supervisor, ['PATH' => (string) getenv('PATH')]);

        return new ExecutionCoordinator(
            new AgentLoopExecutionGateway(new ExecutionGateway($this->root)),
            new RuntimeJournal($layout),
            new RunWorkspaceManager($layout, new GitWorktreeService($git), new WorkspaceCandidateHasher($git)),
            new CompletionEnvelopeParser(),
            $config,
            ['codex' => $host],
            $supervisor,
            new DiagnosticLogStore($layout),
        );
    }

    /** @return non-empty-string */
    private function fakeCodexBinary(): string
    {
        $directory = $this->root . '/fake-bin';
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0o775, true));
        }

        $path = $directory . '/codex';
        $script = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "--version" ]]; then
    printf '%s\n' 'codex-fake 1.0.0'
    exit 0
fi

if [[ "${1:-}" != "exec" || "${2:-}" != "--ephemeral" || "${3:-}" != "-" || "$#" -ne 3 ]]; then
    printf '%s\n' 'unexpected codex invocation' >&2
    exit 64
fi

prompt="$(cat)"

if grep -Fq 'Role: investigator' <<<"$prompt"; then
    printf '%s\n' 'AGENT_LOOP_STAGE_RESULT {"outcome":"completed","summary":"investigated","artifact_references":[],"validation_references":[]}'
    exit 0
fi

if grep -Fq 'Role: builder' <<<"$prompt"; then
    printf '%s\n' '<?php final class Foo { public const string BUILT = "real-process"; }' > src/Foo.php
    printf '%s\n' 'AGENT_LOOP_STAGE_RESULT {"outcome":"completed","summary":"built","artifact_references":["src/Foo.php"],"validation_references":[]}'
    exit 0
fi

if grep -Fq 'Role: reviewer' <<<"$prompt"; then
    printf '%s\n' 'AGENT_LOOP_STAGE_RESULT {"outcome":"pass","summary":"reviewed","artifact_references":[],"validation_references":[]}'
    exit 0
fi

printf '%s\n' 'unknown role in prompt' >&2
exit 65
BASH;

        self::assertNotFalse(file_put_contents($path, $script));
        self::assertTrue(chmod($path, 0o755));

        return $path;
    }

    private function coordinator(OutcomeHost $host): ExecutionCoordinator
    {
        $layout = new RunnerLayout($this->root);
        $supervisor = new ForegroundProcessSupervisor();
        $git = new GitCommand($supervisor, ['PATH' => (string) getenv('PATH')]);

        return new ExecutionCoordinator(
            new AgentLoopExecutionGateway(new ExecutionGateway($this->root)),
            new RuntimeJournal($layout),
            new RunWorkspaceManager($layout, new GitWorktreeService($git), new WorkspaceCandidateHasher($git)),
            new CompletionEnvelopeParser(),
            RunnerConfig::defaults(),
            ['codex' => $host, 'claude' => $host],
            $supervisor,
            new DiagnosticLogStore($layout),
        );
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

    /** @var list<string> */
    public array $prompts = [];

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
        $this->prompts[] = $request->prompt;
        $startedAt = '2026-01-01T00:00:00+00:00';
        $request->observer->started(30_000 + $this->executions, $startedAt);
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
            new ProcessResult(0, $stdout, '', false, $startedAt, '2026-01-01T00:01:00+00:00'),
        );
    }
}
