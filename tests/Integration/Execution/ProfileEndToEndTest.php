<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionContextPolicy;
use voku\AgentLoop\Execution\ExecutionEnvironmentObservation;
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

final class ProfileEndToEndTest extends TestCase
{
    private string $root;
    private string $base;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-profile-' . bin2hex(random_bytes(5));
        mkdir($this->root);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'x@y.test']);
        $this->git(['config', 'user.name', 'X']);
        file_put_contents($this->root . '/a', 'a');
        $this->git(['add', '.']);
        $this->git(['commit', '-qm', 'base']);
        $this->base = trim($this->git(['rev-parse', 'HEAD']));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return iterable<string, array{ExecutionProfileName, list<string>}> */
    public static function profiles(): iterable
    {
        yield 'surgical' => [ExecutionProfileName::SURGICAL, ['investigator', 'builder', 'reviewer', 'verify']];
        yield 'standard' => [ExecutionProfileName::STANDARD, ['investigator', 'builder', 'correctness-review', 'blindspot-review', 'verify']];
        yield 'hardened' => [ExecutionProfileName::HARDENED, ['investigator', 'builder', 'correctness-review', 'architecture-review', 'hardening', 'independent-verification', 'blindspot-review', 'verify']];
    }

    /** @param list<string> $stages */
    #[DataProvider('profiles')]
    public function testProfileCompletesThroughOneCoordinator(ExecutionProfileName $profile, array $stages): void
    {
        $gateway = new ProfileGateway($this->root, $this->base, $profile, $stages);
        $host = new ProfileHost();
        $supervisor = new ForegroundProcessSupervisor();
        $layout = new RunnerLayout($this->root);
        $git = new GitCommand($supervisor, ['PATH' => (string) getenv('PATH')]);
        $coordinator = new ExecutionCoordinator(
            $gateway,
            new RuntimeJournal($layout),
            new RunWorkspaceManager($layout, new GitWorktreeService($git), new WorkspaceCandidateHasher($git)),
            new CompletionEnvelopeParser(),
            RunnerConfig::defaults(),
            ['codex' => $host, 'claude' => $host],
            $supervisor,
            new DiagnosticLogStore($layout),
        );
        $projection = $coordinator->run('TASK');
        self::assertTrue($projection->complete());
        self::assertSame(count($stages) - 1, $host->executions);
        self::assertSame(count($stages) - 1, $host->probes);
        self::assertSame(count($stages) - 1, $gateway->environmentPreparations);
        self::assertSame(1, $gateway->deterministicExecutions);
        self::assertSame($stages, $gateway->visited);

        self::assertArrayHasKey('builder', $gateway->contextIds);
        if (in_array('reviewer', $stages, true)) {
            self::assertArrayHasKey('reviewer', $gateway->contextIds);
            self::assertNotSame($gateway->contextIds['builder'], $gateway->contextIds['reviewer']);
        }
        if (in_array('blindspot-review', $stages, true)) {
            self::assertArrayHasKey('blindspot-review', $gateway->contextIds);
            self::assertNotSame($gateway->contextIds['builder'], $gateway->contextIds['blindspot-review']);
        }
        self::assertCount(count($gateway->contextIds), array_unique($gateway->contextIds));
    }

    /** @param list<string> $args */
    private function git(array $args): string
    {
        $process = proc_open(['git', '-C', $this->root, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);

        return (string) $stdout;
    }
}

final class ProfileGateway implements ExecutionGatewayPort
{
    private int $index = 0;
    public int $deterministicExecutions = 0;
    public int $environmentPreparations = 0;

    /** @var list<string> */
    public array $visited = [];

    /** @var array<non-empty-string, non-empty-string> */
    public array $contextIds = [];

    /** @param list<string> $stages */
    public function __construct(
        private readonly string $root,
        private readonly string $base,
        private readonly ExecutionProfileName $profile,
        private readonly array $stages,
    ) {
    }

    public function projection(string $taskId): ExecutionProjection
    {
        return new ExecutionProjection($taskId, 'RUN', 1, $this->profile, 'sha256:' . str_repeat('d', 64), $this->stages[$this->index] ?? null, 1, null, [], $this->base);
    }

    public function prepareStage(string $taskId, string $stageId): StageExecutionBundle
    {
        $deterministic = $stageId === 'verify';
        $freshContext = in_array(
            $stageId,
            ['reviewer', 'correctness-review', 'architecture-review', 'independent-verification', 'blindspot-review'],
            true,
        );
        $contextIdRequired = !$deterministic && $stageId !== 'investigator';

        return new StageExecutionBundle(
            $taskId,
            'RUN',
            1,
            'sha256:' . str_repeat('d', 64),
            $stageId,
            1,
            $deterministic ? ExecutionStageKind::DETERMINISTIC : ExecutionStageKind::AGENT,
            $deterministic ? null : $stageId,
            !in_array($stageId, ['investigator', 'reviewer', 'correctness-review', 'architecture-review', 'independent-verification', 'blindspot-review'], true),
            $this->root,
            $this->base,
            $this->base,
            ['path' => 'contract', 'sha256' => 'sha256:' . str_repeat('e', 64)],
            null,
            ['src'],
            ['composer ci'],
            null,
            [StageOutcome::PASS, StageOutcome::FAILED],
            'AGENT_LOOP_STAGE_RESULT ',
            'work',
            contextPolicy: $freshContext
                ? ExecutionContextPolicy::FRESH_REQUIRED
                : ExecutionContextPolicy::REUSE_ALLOWED,
            contextIdRequired: $contextIdRequired,
        );
    }

    public function prepareStageForEnvironment(
        string $taskId,
        string $stageId,
        ExecutionEnvironmentObservation $observation,
    ): StageExecutionBundle {
        ++$this->environmentPreparations;
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
            contextPolicy: $bundle->contextPolicy,
            contextIdRequired: $bundle->contextIdRequired,
        );
    }

    public function recordStageCandidate(StageCandidateObservation $observation): string
    {
        throw new RuntimeException('Candidate observation is not expected in no-op profile fixtures.');
    }

    public function recordStageArtifact(StageArtifactObservation $observation): string
    {
        throw new RuntimeException('Artifact observation is not expected in no-op profile fixtures.');
    }

    public function submitStageResult(StageResult $result): ExecutionProjection
    {
        $this->visited[] = $result->stageId;
        if ($result->contextId !== null) {
            $this->contextIds[$result->stageId] = $result->contextId;
        }
        ++$this->index;

        return $this->projection($result->taskId);
    }

    public function runDeterministicStage(string $taskId, string $stageId): ExecutionProjection
    {
        ++$this->deterministicExecutions;
        $this->visited[] = $stageId;
        ++$this->index;

        return $this->projection($taskId);
    }
}

final class ProfileHost implements HostAdapter
{
    public int $executions = 0;
    public int $probes = 0;

    public function id(): string
    {
        return 'fake';
    }

    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability
    {
        ++$this->probes;

        return new HostAvailability('fake', 'fake', '1', null);
    }

    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        ++$this->executions;
        $startedAt = sprintf('2026-01-01T00:00:%02d+00:00', $this->executions);
        $finishedAt = sprintf('2026-01-01T00:01:%02d+00:00', $this->executions);

        return new HostExecutionResult(
            'fake',
            new ProcessResult(
                0,
                'AGENT_LOOP_STAGE_RESULT {"outcome":"pass","summary":"ok","artifact_references":[],"validation_references":[]}' . "\n",
                '',
                false,
                $startedAt,
                $finishedAt,
            ),
        );
    }
}
