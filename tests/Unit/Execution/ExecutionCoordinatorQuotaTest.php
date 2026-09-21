<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\CurrentExecutionStageProjection;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageArtifactObservation;
use voku\AgentLoop\Execution\StageCandidateObservation;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Diagnostics\DiagnosticLogStore;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionCoordinator;
use voku\AgentLoopRunner\Execution\ExecutionGatewayPort;
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

final class ExecutionCoordinatorQuotaTest extends TestCase
{
    private string $root;
    private string $base;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-quota-coord-' . bin2hex(random_bytes(5));
        mkdir($this->root);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
        file_put_contents($this->root . '/file.txt', "base\n");
        $this->git(['add', '.']);
        $this->git(['commit', '-qm', 'base']);
        $this->base = trim($this->git(['rev-parse', 'HEAD']));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testHardBreakWhenQuotaExceededAndNoFallbackConfigured(): void
    {
        $gateway = new QuotaFakeGateway('TASK-QUOTA', $this->base, $this->root);
        $exhaustedHost = new QuotaFakeHost('codex', true, remainingRatio: 0.03, resetAt: time() + 3600); // 97% used
        $config = new RunnerConfig(
            ['codex' => ['binary' => 'codex']],
            ['builder' => 'codex'],
            1800,
            ['PATH'],
        );

        $coordinator = $this->makeCoordinator($gateway, $config, ['codex' => $exhaustedHost]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("QUOTA_LIMIT_REACHED: Host 'codex' for role 'builder' has reached 97.0% token/quota usage (threshold: 95.0%).");

        try {
            $coordinator->run('TASK-QUOTA');
        } catch (RuntimeException $exception) {
            $msg = $exception->getMessage();
            self::assertStringContainsString('Execution was halted before starting to prevent the task from failing mid-execution.', $msg);
            self::assertStringContainsString('Quota resets at', $msg);
            self::assertStringContainsString("No fallback host is configured for role 'builder'.", $msg);
            self::assertStringContainsString("To configure a fallback host, add 'role_fallbacks' or 'fallback' in .agent-loop-runner/config.json:", $msg);
            self::assertStringContainsString('"role_fallbacks": {', $msg);
            self::assertStringContainsString('"builder": "<fallback-host>"', $msg);
            self::assertStringContainsString('Supported hosts: codex, claude, opencode, agy.', $msg);
            self::assertDirectoryDoesNotExist($this->root . '/.agent-loop-runner/worktrees');
            throw $exception;
        }
    }

    public function testFallbackUsedWhenPrimaryHostQuotaExceeded(): void
    {
        $gateway = new QuotaFakeGateway('TASK-FALLBACK', $this->base, $this->root);
        $exhaustedHost = new QuotaFakeHost('codex', true, remainingRatio: 0.02); // 98% used
        $healthyFallbackHost = new QuotaFakeHost('claude', true, remainingRatio: 0.85); // 15% used

        $config = new RunnerConfig(
            [
                'codex' => ['binary' => 'codex'],
                'claude' => ['binary' => 'claude'],
            ],
            ['builder' => 'codex'],
            1800,
            ['PATH'],
            [],
            ['builder' => 'claude'], // Fallback host configured!
        );

        $coordinator = $this->makeCoordinator($gateway, $config, [
            'codex' => $exhaustedHost,
            'claude' => $healthyFallbackHost,
        ]);

        $projection = $coordinator->run('TASK-FALLBACK');

        self::assertTrue($projection->complete());
        self::assertSame(0, $exhaustedHost->executions, 'Exhausted primary host must not be executed');
        self::assertSame(1, $healthyFallbackHost->executions, 'Healthy fallback host must be executed');
        self::assertSame('claude', $gateway->observedHostId, 'Environment observation must record fallback host');
    }

    public function testHardBreakWhenFallbackHostIsAlsoExhausted(): void
    {
        $gateway = new QuotaFakeGateway('TASK-BOTH-EXHAUSTED', $this->base, $this->root);
        $exhaustedHost = new QuotaFakeHost('codex', true, remainingRatio: 0.01); // 99% used
        $exhaustedFallback = new QuotaFakeHost('claude', true, remainingRatio: 0.04); // 96% used

        $config = new RunnerConfig(
            [
                'codex' => ['binary' => 'codex'],
                'claude' => ['binary' => 'claude'],
            ],
            ['builder' => 'codex'],
            1800,
            ['PATH'],
            [],
            ['builder' => 'claude'],
        );

        $coordinator = $this->makeCoordinator($gateway, $config, [
            'codex' => $exhaustedHost,
            'claude' => $exhaustedFallback,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("QUOTA_LIMIT_REACHED: Host 'codex' for role 'builder' has reached 99.0% token/quota usage");

        try {
            $coordinator->run('TASK-BOTH-EXHAUSTED');
        } catch (RuntimeException $exception) {
            $msg = $exception->getMessage();
            self::assertStringContainsString("Configured fallback host 'claude' could not be used", $msg);
            self::assertStringContainsString('near limit (96.0%)', $msg);
            throw $exception;
        }
    }

    /**
     * @param array<string, HostAdapter> $hosts
     */
    private function makeCoordinator(QuotaFakeGateway $gateway, RunnerConfig $config, array $hosts): ExecutionCoordinator
    {
        $supervisor = new ForegroundProcessSupervisor();
        $layout = new RunnerLayout($this->root);
        $git = new GitCommand($supervisor, ['PATH' => (string) getenv('PATH')]);

        return new ExecutionCoordinator(
            $gateway,
            new RuntimeJournal($layout),
            new RunWorkspaceManager($layout, new GitWorktreeService($git), new WorkspaceCandidateHasher($git)),
            new CompletionEnvelopeParser(),
            $config,
            $hosts,
            $supervisor,
            new DiagnosticLogStore($layout),
        );
    }

    /** @param list<string> $args */
    private function git(array $args): string
    {
        $process = proc_open(['git', '-C', $this->root, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('git proc_open failed');
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return (string) $out;
    }
}

final class QuotaFakeHost implements HostAdapter
{
    public int $probes = 0;
    public int $executions = 0;

    public function __construct(
        private string $id,
        private bool $available,
        private ?float $remainingRatio = null,
        private ?int $resetAt = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability
    {
        ++$this->probes;

        return new HostAvailability(
            $this->id,
            $this->available ? '/bin/' . $this->id : null,
            $this->available ? '1.0' : null,
            $this->available ? null : 'binary not found',
            $this->remainingRatio,
            $this->resetAt,
        );
    }

    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        ++$this->executions;

        return new HostExecutionResult($this->id, new ProcessResult(
            0,
            'AGENT_LOOP_STAGE_RESULT {"outcome":"pass","summary":"done","artifact_references":[],"validation_references":[]}' . "\n",
            '',
            false,
            '2026-09-21T08:00:00Z',
            '2026-09-21T08:00:01Z',
        ));
    }
}

final class QuotaFakeGateway implements ExecutionGatewayPort
{
    public ?string $observedHostId = null;
    private bool $completed = false;

    public function __construct(
        private string $taskId,
        private string $baseSha,
        private string $root,
    ) {
    }

    public function projection(string $taskId): ExecutionProjection
    {
        return new ExecutionProjection(
            $this->taskId,
            'run:' . $this->taskId,
            1,
            ExecutionProfileName::SURGICAL,
            'sha256:' . str_repeat('a', 64),
            $this->completed ? null : 'builder',
            1,
            null,
            [],
            $this->baseSha,
        );
    }

    public function prepareStage(string $taskId, string $stageId): StageExecutionBundle
    {
        return new StageExecutionBundle(
            taskId: $this->taskId,
            runId: 'run:' . $this->taskId,
            contractRevision: 1,
            executionPlanDigest: 'sha256:' . str_repeat('a', 64),
            stageId: $stageId,
            attempt: 1,
            kind: ExecutionStageKind::AGENT,
            roleId: 'builder',
            mayMutate: true,
            repositoryRoot: $this->root,
            baseCommit: $this->baseSha,
            candidateRevision: $this->baseSha,
            contractSource: ['path' => 'contract', 'sha256' => 'sha256:' . str_repeat('b', 64)],
            recallSource: null,
            allowedScope: ['file.txt'],
            requiredValidation: ['composer ci'],
            priorHandoff: null,
            acceptedOutcomes: [StageOutcome::PASS, StageOutcome::FAILED],
            completionMarker: 'AGENT_LOOP_STAGE_RESULT ',
            prompt: 'Do work',
        );
    }

    public function prepareStageForEnvironment(
        string $taskId,
        string $stageId,
        \voku\AgentLoop\Execution\ExecutionEnvironmentObservation $observation,
    ): StageExecutionBundle {
        $this->observedHostId = $observation->hostId;
        $bundle = $this->prepareStage($taskId, $stageId);

        return new StageExecutionBundle(
            taskId: $bundle->taskId,
            runId: $bundle->runId,
            contractRevision: $bundle->contractRevision,
            executionPlanDigest: $bundle->executionPlanDigest,
            stageId: $bundle->stageId,
            attempt: $bundle->attempt,
            kind: $bundle->kind,
            roleId: $bundle->roleId,
            mayMutate: $bundle->mayMutate,
            repositoryRoot: $bundle->repositoryRoot,
            baseCommit: $bundle->baseCommit,
            candidateRevision: $bundle->candidateRevision,
            contractSource: $bundle->contractSource,
            recallSource: $bundle->recallSource,
            allowedScope: $bundle->allowedScope,
            requiredValidation: $bundle->requiredValidation,
            priorHandoff: $bundle->priorHandoff,
            acceptedOutcomes: $bundle->acceptedOutcomes,
            completionMarker: $bundle->completionMarker,
            prompt: $bundle->prompt . "\nenvironment=" . $observation->digest(),
            environmentObservationDigest: $observation->digest(),
        );
    }

    public function recordStageCandidate(StageCandidateObservation $observation): string
    {
        return 'execution-evidence:sha256:' . hash('sha256', $observation->candidateRevision);
    }

    public function recordStageArtifact(StageArtifactObservation $observation): string
    {
        return 'execution-evidence:sha256:' . hash('sha256', $observation->sourceReference . "\0" . $observation->sourceDigest);
    }

    public function runDeterministicStage(string $taskId, string $stageId): ExecutionProjection
    {
        throw new RuntimeException('unexpected deterministic stage');
    }

    public function submitStageResult(StageResult $result): ExecutionProjection
    {
        $this->completed = true;

        return $this->projection($result->taskId);
    }
}
