<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use JsonException;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Execution\AgentLoopExecutionOwner;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionOwner;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\HostAdapterFactory;
use voku\AgentLoopRunner\Process\EnvironmentProjector;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\OwnedProcessCanceller;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\DiagnosticLogStore;
use voku\AgentLoopRunner\Runtime\RuntimeJournalStore;
use voku\AgentLoopRunner\Runtime\RuntimeReconciler;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

final readonly class RunnerApplication
{
    private const int EXIT_SUCCESS = 0;
    private const int EXIT_USAGE = 2;
    private const int EXIT_DOCTOR_FAILED = 3;
    private const int EXIT_WAITING_FOR_ATTENTION = 20;

    public function __construct(
        private RunnerLayout $layout,
        private RunnerConfig $config,
        private ExecutionOwner $owner,
        private ExecutionCoordinator $coordinator,
        private RunnerControl $control,
        private RuntimeJournalStore $journals,
        private GitWorktreeService $gitWorktrees,
        private HostAdapterFactory $hostAdapters,
        private ProcessSupervisor $processes,
        private EnvironmentProjector $environment,
    ) {
    }

    public static function create(string $projectRoot): self
    {
        $layout = new RunnerLayout($projectRoot);
        $root = $layout->projectRoot();
        $config = RunnerConfig::load($root);
        $processes = new ForegroundProcessSupervisor();
        $environment = new EnvironmentProjector();
        $gitEnvironment = $environment->project([
            'PATH', 'HOME', 'USER', 'LOGNAME', 'TMPDIR', 'TEMP', 'TMP',
        ]);
        $git = new GitCommand($processes, $gitEnvironment);
        $gitWorktrees = new GitWorktreeService($git);
        $workspaces = new RunWorkspaceManager(
            $layout,
            $gitWorktrees,
            new WorkspaceCandidateHasher($git),
        );
        $journals = new RuntimeJournalStore($layout);
        $owner = new AgentLoopExecutionOwner($root);
        $hostAdapters = new HostAdapterFactory();
        $processCanceller = new OwnedProcessCanceller();
        $coordinator = new ExecutionCoordinator(
            $root,
            $owner,
            $config,
            $hostAdapters,
            $processes,
            $environment,
            $workspaces,
            $journals,
            new RuntimeReconciler(),
            new CompletionEnvelopeParser(),
            new DiagnosticLogStore($layout),
            $processCanceller,
        );
        $control = new RunnerControl(
            $owner,
            $coordinator,
            $journals,
            new RuntimeReconciler(),
            $processCanceller,
            $workspaces,
        );

        return new self(
            $layout,
            $config,
            $owner,
            $coordinator,
            $control,
            $journals,
            $gitWorktrees,
            $hostAdapters,
            $processes,
            $environment,
        );
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments): int
    {
        $command = $arguments[0] ?? 'help';
        if ($command === 'help' || $command === '--help' || $command === '-h') {
            fwrite(STDOUT, $this->usage());

            return self::EXIT_SUCCESS;
        }

        if ($command === 'doctor') {
            if (count($arguments) !== 1) {
                fwrite(STDERR, $this->usage());

                return self::EXIT_USAGE;
            }
            $report = $this->doctor();
            $this->json($report);

            return $report['ok'] ? self::EXIT_SUCCESS : self::EXIT_DOCTOR_FAILED;
        }

        if (!in_array($command, ['status', 'run', 'resume', 'cancel', 'cleanup'], true) || count($arguments) !== 2) {
            fwrite(STDERR, $this->usage());

            return self::EXIT_USAGE;
        }
        $taskId = trim($arguments[1]);
        if ($taskId === '') {
            throw new RuntimeException('STALE_RUN: task id must be non-empty.');
        }

        if ($command === 'status') {
            $this->json($this->status($taskId));

            return self::EXIT_SUCCESS;
        }
        if ($command === 'cleanup') {
            $this->control->cleanup($taskId);
            $this->json(['result' => 'cleaned', 'task_id' => $taskId]);

            return self::EXIT_SUCCESS;
        }

        $projection = match ($command) {
            'run', 'resume' => $this->coordinator->run($taskId),
            'cancel' => $this->control->cancel($taskId),
            default => throw new RuntimeException('Unsupported Runner command.'),
        };
        $this->json($this->projection($projection));

        return $projection->attention !== null
            ? self::EXIT_WAITING_FOR_ATTENTION
            : self::EXIT_SUCCESS;
    }

    /** @return array<string, mixed> */
    private function doctor(): array
    {
        $root = $this->layout->projectRoot();
        $repository = null;
        $repositoryFailure = null;
        try {
            $repository = $this->gitWorktrees->assertRepository($root);
        } catch (RuntimeException $exception) {
            $repositoryFailure = $exception->getMessage();
        }

        $environment = $this->environment->project([
            'PATH', 'HOME', 'USER', 'LOGNAME', 'TMPDIR', 'TEMP', 'TMP',
        ]);
        $hostIds = array_values(array_unique(array_values($this->config->roles)));
        sort($hostIds, SORT_STRING);
        $hosts = [];
        $hostsOk = true;
        foreach ($hostIds as $hostId) {
            $adapter = $this->hostAdapters->create($hostId, $this->config);
            $availability = $adapter->probe($this->processes, $root, $environment);
            $hosts[$hostId] = [
                'available' => $availability->available(),
                'binary' => $availability->binaryPath,
                'version' => $availability->version,
                'failure' => $availability->failure,
            ];
            $hostsOk = $hostsOk && $availability->available();
        }

        $gatewayAvailable = class_exists(ExecutionGateway::class);
        $phpOk = version_compare(PHP_VERSION, '8.3.0', '>=');
        $ok = $phpOk && $gatewayAvailable && $repository !== null && $hostsOk;

        return [
            'ok' => $ok,
            'php' => ['version' => PHP_VERSION, 'supported' => $phpOk],
            'repository' => ['root' => $repository, 'failure' => $repositoryFailure],
            'agent_loop_execution_gateway' => $gatewayAvailable,
            'hosts' => $hosts,
        ];
    }

    /** @return array<string, mixed> */
    private function status(string $taskId): array
    {
        return [
            'authority' => $this->projection($this->owner->projection($taskId)),
            'runner_observation' => $this->journals->load($taskId)?->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function projection(ExecutionProjection $projection): array
    {
        return [
            'task_id' => $projection->taskId,
            'run_id' => $projection->runId,
            'contract_revision' => $projection->contractRevision,
            'profile' => $projection->profile->value,
            'execution_plan_digest' => $projection->executionPlanDigest,
            'current_stage_id' => $projection->currentStageId,
            'current_attempt' => $projection->currentAttempt,
            'candidate_revision' => $projection->candidateRevision,
            'complete' => $projection->complete(),
            'attention' => $projection->attention?->toArray(),
        ];
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): void
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Runner command output.', 0, $exception);
        }
        fwrite(STDOUT, $json . "\n");
    }

    private function usage(): string
    {
        return <<<'TEXT'
agent-loop-runner - optional governed execution plane for voku/agent-loop

Usage:
  agent-loop-runner doctor
  agent-loop-runner status <task-id>
  agent-loop-runner run <task-id>
  agent-loop-runner resume <task-id>
  agent-loop-runner cancel <task-id>
  agent-loop-runner cleanup <task-id>
  agent-loop-runner help

`run` and `resume` use the same authoritative reconciliation path.

TEXT;
    }
}
