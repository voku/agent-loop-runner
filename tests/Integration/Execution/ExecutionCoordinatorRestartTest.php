<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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
use voku\AgentLoopRunner\Execution\CoordinatorHook;
use voku\AgentLoopRunner\Execution\ExecutionCoordinator;
use voku\AgentLoopRunner\Execution\ExecutionGatewayPort;
use voku\AgentLoopRunner\Execution\NullCoordinatorHook;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\HostAvailability;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Host\HostExecutionResult;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\ProcessResult;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RunExecutionLock;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

final class ExecutionCoordinatorRestartTest extends TestCase
{
    private string $root;
    private string $base;
    private RuntimeJournal $journal;
    private RunWorkspaceManager $workspaces;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-coordinate-' . bin2hex(random_bytes(5));
        mkdir($this->root);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'x@y.test']);
        $this->git(['config', 'user.name', 'X']);
        file_put_contents($this->root . '/file.txt', 'base');
        $this->git(['add', '.']);
        $this->git(['commit', '-qm', 'base']);
        $this->base = trim($this->git(['rev-parse', 'HEAD']));
        $layout = new RunnerLayout($this->root);
        $command = new GitCommand(new ForegroundProcessSupervisor(), ['PATH' => (string) getenv('PATH')]);
        $this->journal = new RuntimeJournal($layout);
        $this->workspaces = new RunWorkspaceManager($layout, new GitWorktreeService($command), new WorkspaceCandidateHasher($command));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function recoverableBoundaries(): iterable
    {
        yield 'process exited' => ['after_process_exit'];
        yield 'candidate observed' => ['after_candidate_observation'];
        yield 'evidence registered' => ['after_evidence_registration'];
        yield 'result persisted' => ['after_result_persisted'];
        yield 'Loop accepted' => ['after_submission_accepted'];
        yield 'Runner reconciled' => ['after_reconciled_accepted'];
    }

    #[DataProvider('recoverableBoundaries')]
    public function testCrashAfterDurableBoundaryNeverExecutesHostTwice(string $boundary): void
    {
        $gateway = new FakeGateway($this->root, $this->base);
        $host = new MutatingCountingHost();
        try {
            $this->coordinator($gateway, $host, new ThrowAtBoundary($boundary))->run('TASK');
            self::fail('Expected injected crash.');
        } catch (InjectedCrash) {
        }

        self::assertSame(1, $host->executions);
        $complete = $this->coordinator($gateway, $host, new NullCoordinatorHook())->resume('TASK');
        self::assertTrue($complete->complete());
        self::assertSame(1, $host->executions, 'Restart after a durable post-process boundary must not execute the host again.');
        self::assertSame(1, $gateway->submissions);
    }

    public function testCrashBeforeProcessReusesStableSubmissionAndReobservesCurrentEnvironment(): void
    {
        $gateway = new FakeGateway($this->root, $this->base);
        $host = new MutatingCountingHost();
        try {
            $this->coordinator($gateway, $host, new ThrowAtBoundary('before_process_start'))->run('TASK');
            self::fail('Expected injected crash.');
        } catch (InjectedCrash) {
        }
        $submission = $this->journal->load('TASK')?->submissionId;
        self::assertSame(1, $host->probes);
        $host->version = '2';

        $this->coordinator($gateway, $host, new NullCoordinatorHook())->resume('TASK');
        self::assertSame(1, $host->executions);
        self::assertSame(2, $host->probes);
        self::assertSame(2, $gateway->environmentPreparations);
        self::assertSame('2', $gateway->lastEnvironmentObservation?->tools[0]->version);
        self::assertSame($submission, $gateway->lastSubmissionId);
    }

    public function testEnvironmentObservationDoesNotCopyAllowlistedSecretValuesIntoPromptFacts(): void
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=runner-secret-must-not-enter-observation');
        try {
            $gateway = new FakeGateway($this->root, $this->base);
            $host = new MutatingCountingHost();
            $this->coordinator($gateway, $host, new NullCoordinatorHook())->run('TASK');

            self::assertSame(1, $host->probes);
            self::assertSame(1, $gateway->environmentPreparations);
            self::assertNotNull($gateway->lastEnvironmentObservation);
            self::assertSame('codex', $gateway->lastEnvironmentObservation->hostId);
            self::assertSame('codex', $gateway->lastEnvironmentObservation->tools[0]->id);
            self::assertStringNotContainsString(
                'runner-secret-must-not-enter-observation',
                json_encode($gateway->lastEnvironmentObservation->toArray(), JSON_THROW_ON_ERROR),
            );
            self::assertStringContainsString('environment-bound:', $host->lastPrompt ?? '');
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
    }

    public function testUnavailableSelectedHostFailsBeforeEnvironmentPromptOrExecution(): void
    {
        $gateway = new FakeGateway($this->root, $this->base);
        $host = new MutatingCountingHost(available: false);

        try {
            $this->coordinator($gateway, $host, new NullCoordinatorHook())->run('TASK');
            self::fail('Expected unavailable host rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame('HOST_UNAVAILABLE: codex', $exception->getMessage());
        }

        self::assertSame(1, $host->probes);
        self::assertSame(0, $host->executions);
        self::assertSame(0, $gateway->environmentPreparations);
    }

    public function testCrashAfterProcessStartFailsClosedWithoutSecondHostExecution(): void
    {
        $gateway = new FakeGateway($this->root, $this->base);
        $startingHost = new StartThenCrashHost();
        try {
            $this->coordinator($gateway, $startingHost, new NullCoordinatorHook())->run('TASK');
            self::fail('Expected injected crash after process start observation.');
        } catch (InjectedCrash) {
        }
        self::assertSame(AttemptStatus::ProcessStarted, $this->journal->load('TASK')?->status);

        $replacementHost = new MutatingCountingHost();
        try {
            $this->coordinator($gateway, $replacementHost, new NullCoordinatorHook())->resume('TASK');
            self::fail('Expected fail-closed unresolved process observation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('incomplete prior process observation', $exception->getMessage());
        }
        self::assertSame(0, $replacementHost->executions);
    }

    public function testConcurrentRunnerForSameTaskFailsBeforeSecondHostExecution(): void
    {
        $lock = RunExecutionLock::acquire(new RunnerLayout($this->root), 'TASK');
        $gateway = new FakeGateway($this->root, $this->base);
        $host = new MutatingCountingHost();
        try {
            $this->coordinator($gateway, $host, new NullCoordinatorHook())->run('TASK');
            self::fail('Expected same-task execution lock rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('STALE_RUN: another Runner process', $exception->getMessage());
            self::assertSame(0, $host->executions);
        } finally {
            $lock->release();
        }
    }

    private function coordinator(FakeGateway $gateway, HostAdapter $host, CoordinatorHook $hook): ExecutionCoordinator
    {
        return new ExecutionCoordinator(
            $gateway,
            $this->journal,
            $this->workspaces,
            new CompletionEnvelopeParser(),
            RunnerConfig::defaults(),
            ['codex' => $host],
            new ForegroundProcessSupervisor(),
            new DiagnosticLogStore(new RunnerLayout($this->root)),
            $hook,
        );
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

final class FakeGateway implements ExecutionGatewayPort
{
    public bool $complete = false;
    public int $submissions = 0;
    public int $candidateRegistrations = 0;
    public int $artifactRegistrations = 0;
    public int $environmentPreparations = 0;
    public ?string $lastSubmissionId = null;
    public ?ExecutionEnvironmentObservation $lastEnvironmentObservation = null;

    public function __construct(private readonly string $root, private readonly string $base)
    {
    }

    public function projection(string $taskId): ExecutionProjection
    {
        return new ExecutionProjection(
            $taskId,
            'RUN',
            1,
            ExecutionProfileName::SURGICAL,
            'sha256:' . str_repeat('a', 64),
            $this->complete ? null : 'builder',
            1,
            null,
            [],
            $this->base,
        );
    }

    public function prepareStage(string $taskId, string $stageId): StageExecutionBundle
    {
        return new StageExecutionBundle(
            $taskId,
            'RUN',
            1,
            'sha256:' . str_repeat('a', 64),
            $stageId,
            1,
            ExecutionStageKind::AGENT,
            'builder',
            true,
            $this->root,
            $this->base,
            $this->base,
            ['path' => 'contract', 'sha256' => 'sha256:' . str_repeat('b', 64)],
            null,
            ['src'],
            ['test'],
            null,
            [StageOutcome::PASS, StageOutcome::FAILED],
            'AGENT_LOOP_STAGE_RESULT ',
            "do work\n",
        );
    }

    public function prepareStageForEnvironment(
        string $taskId,
        string $stageId,
        ExecutionEnvironmentObservation $observation,
    ): StageExecutionBundle {
        ++$this->environmentPreparations;
        $this->lastEnvironmentObservation = $observation;
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
            prompt: 'environment-bound:' . $observation->digest() . "\n",
            environmentObservationDigest: $observation->digest(),
        );
    }

    public function recordStageCandidate(StageCandidateObservation $observation): string
    {
        ++$this->candidateRegistrations;

        return 'execution-evidence:sha256:' . hash('sha256', $observation->candidateRevision);
    }

    public function recordStageArtifact(StageArtifactObservation $observation): string
    {
        ++$this->artifactRegistrations;

        return 'execution-evidence:sha256:' . hash('sha256', $observation->sourceReference . "\0" . $observation->sourceDigest);
    }

    public function submitStageResult(StageResult $result): ExecutionProjection
    {
        ++$this->submissions;
        $this->lastSubmissionId = $result->submissionId;
        $this->complete = true;

        return $this->projection($result->taskId);
    }

    public function runDeterministicStage(string $taskId, string $stageId): ExecutionProjection
    {
        throw new RuntimeException('not expected');
    }
}

final class MutatingCountingHost implements HostAdapter
{
    public int $executions = 0;
    public int $probes = 0;
    public string $version = '1';
    public ?string $lastPrompt = null;

    public function __construct(private readonly bool $available = true)
    {
    }

    public function id(): string
    {
        return 'codex';
    }

    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability
    {
        ++$this->probes;

        return $this->available
            ? new HostAvailability('codex', 'fake', $this->version, null)
            : new HostAvailability('codex', null, null, 'binary not found');
    }

    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        ++$this->executions;
        $this->lastPrompt = $request->prompt;
        file_put_contents($request->workingDirectory . '/candidate.txt', 'candidate');
        file_put_contents($request->workingDirectory . '/artifact.txt', 'artifact');

        return new HostExecutionResult(
            'codex',
            new ProcessResult(
                0,
                "AGENT_LOOP_STAGE_RESULT {\"outcome\":\"pass\",\"summary\":\"done\",\"artifact_references\":[\"artifact.txt\"],\"validation_references\":[]}\n",
                '',
                false,
                '2026-01-01T00:00:00+00:00',
                '2026-01-01T00:00:01+00:00',
            ),
        );
    }
}

final class StartThenCrashHost implements HostAdapter
{
    public function id(): string
    {
        return 'codex';
    }

    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability
    {
        return new HostAvailability('codex', 'fake', '1', null);
    }

    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        $request->observer->started(999_999, '2026-01-01T00:00:00+00:00');
        throw new InjectedCrash();
    }
}

final readonly class ThrowAtBoundary implements CoordinatorHook
{
    public function __construct(private string $boundary)
    {
    }

    public function reached(string $boundary): void
    {
        if ($boundary === $this->boundary) {
            throw new InjectedCrash();
        }
    }
}

final class InjectedCrash extends RuntimeException
{
}
