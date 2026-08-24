<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Diagnostics\DiagnosticLogStore;
use voku\AgentLoopRunner\Execution\AgentLoopExecutionGateway;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionCoordinator;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\ClaudeHostAdapter;
use voku\AgentLoopRunner\Host\CodexHostAdapter;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\OpenCodeHostAdapter;
use voku\AgentLoopRunner\Process\EnvironmentProjector;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\ProcessIdentity;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RunExecutionLock;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

final readonly class RunnerApplication
{
    public function __construct(private string $projectRoot)
    {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $command = $arguments[0] ?? 'help';
        $task = $arguments[1] ?? null;
        try {
            return match ($command) {
                'doctor' => $this->doctor(),
                'status' => $this->withTask($task, fn (string $id): int => $this->status($id)),
                'run', 'resume' => $this->withTask($task, fn (string $id): int => $this->execute($id, $command)),
                'cancel' => $this->withTask($task, fn (string $id): int => $this->cancel($id)),
                'cleanup' => $this->withTask($task, fn (string $id): int => $this->cleanup($id)),
                default => $this->usage(),
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n");

            return $this->failureCode($exception->getMessage());
        }
    }

    private function doctor(): int
    {
        $supervisor = new ForegroundProcessSupervisor();
        $config = RunnerConfig::load($this->projectRoot);
        $environment = (new EnvironmentProjector())->project($config->environmentAllowlist);
        $checks = [
            'php' => PHP_VERSION,
            'git' => $this->gitVersion($supervisor),
            'execution_gateway' => class_exists(ExecutionGateway::class) ? 'available' : 'missing',
        ];
        foreach ($this->hosts($config) as $id => $host) {
            $availability = $host->probe($supervisor, $this->projectRoot, $environment);
            $checks['host_' . $id] = $availability->available()
                ? ($availability->version ?? 'available')
                : 'unavailable';
        }
        $this->json($checks);

        return in_array('missing', $checks, true) ? ExitCode::TRANSITION_REJECTED : ExitCode::OK;
    }

    private function status(string $task): int
    {
        $projection = (new ExecutionGateway($this->projectRoot))->projection($task);
        $local = (new RuntimeJournal(new RunnerLayout($this->projectRoot)))->load($task);
        $this->json([
            'authority' => [
                'task_id' => $projection->taskId,
                'run_id' => $projection->runId,
                'contract_revision' => $projection->contractRevision,
                'execution_plan_digest' => $projection->executionPlanDigest,
                'current_stage_id' => $projection->currentStageId,
                'current_attempt' => $projection->currentAttempt,
                'attention_id' => $projection->attention?->id,
                'complete' => $projection->complete(),
                'candidate_revision' => $projection->candidateRevision,
            ],
            'runner_observation' => $local?->toArray(),
        ]);

        return ExitCode::OK;
    }

    private function execute(string $task, string $command): int
    {
        $config = RunnerConfig::load($this->projectRoot);
        $layout = new RunnerLayout($this->projectRoot);
        $supervisor = new ForegroundProcessSupervisor();
        $git = new GitCommand($supervisor, (new EnvironmentProjector())->project(['PATH', 'HOME']));
        $coordinator = new ExecutionCoordinator(
            new AgentLoopExecutionGateway(new ExecutionGateway($this->projectRoot)),
            new RuntimeJournal($layout),
            new RunWorkspaceManager($layout, new GitWorktreeService($git), new WorkspaceCandidateHasher($git)),
            new CompletionEnvelopeParser(),
            $config,
            $this->hosts($config),
            $supervisor,
            new DiagnosticLogStore($layout),
        );
        $projection = $command === 'run' ? $coordinator->run($task) : $coordinator->resume($task);
        $this->json([
            'task_id' => $projection->taskId,
            'complete' => $projection->complete(),
            'current_stage_id' => $projection->currentStageId,
        ]);

        return ExitCode::OK;
    }

    private function cancel(string $task): int
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->projectRoot));
        $attempt = $journal->load($task);
        if ($attempt === null || $attempt->status !== AttemptStatus::ProcessStarted || !isset($attempt->process['pid'])) {
            throw new RuntimeException('PROCESS_FAILED: no owned active process is recorded.');
        }

        $journal->cancel($attempt, static function (RuntimeAttempt $current): bool {
            $pid = $current->process['pid'] ?? null;
            $fingerprint = $current->process['process_fingerprint'] ?? null;
            if (!is_int($pid)
                || !is_string($fingerprint)
                || !hash_equals($fingerprint, ProcessIdentity::fingerprint($pid) ?? '')) {
                throw new RuntimeException('PROCESS_FAILED: owned process identity is stale.');
            }
            if (PHP_OS_FAMILY === 'Windows'
                || !function_exists('posix_kill')
                || !function_exists('posix_getpgid')) {
                throw new RuntimeException('PROCESS_FAILED: process-group cancellation is unavailable.');
            }
            $groupId = posix_getpgid($pid);
            if (!is_int($groupId) || $groupId !== $pid) {
                throw new RuntimeException('PROCESS_FAILED: owned process is not in an isolated process group.');
            }

            return posix_kill(-$pid, defined('SIGTERM') ? SIGTERM : 15);
        });

        return ExitCode::OK;
    }

    private function cleanup(string $task): int
    {
        $layout = new RunnerLayout($this->projectRoot);
        $lock = RunExecutionLock::acquire($layout, $task);
        try {
            $journal = new RuntimeJournal($layout);
            $attempt = $journal->load($task);
            if ($attempt === null) {
                throw new RuntimeException('STALE_RUN: no Runner observation identifies a workspace.');
            }
            if (!in_array($attempt->status, [AttemptStatus::ReconciledAccepted, AttemptStatus::Cancelled], true)) {
                throw new RuntimeException('STALE_WORKSPACE: workspace has unreconciled evidence.');
            }
            $supervisor = new ForegroundProcessSupervisor();
            $git = new GitCommand($supervisor, (new EnvironmentProjector())->project(['PATH', 'HOME']));
            $manager = new RunWorkspaceManager(
                $layout,
                new GitWorktreeService($git),
                new WorkspaceCandidateHasher($git),
            );
            $manager->cleanup($attempt->taskId, $attempt->runId);

            return ExitCode::OK;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, HostAdapter> */
    private function hosts(RunnerConfig $config): array
    {
        return [
            'codex' => new CodexHostAdapter($config->binary('codex')),
            'claude' => new ClaudeHostAdapter($config->binary('claude')),
            'opencode' => new OpenCodeHostAdapter($config->binary('opencode')),
        ];
    }

    private function gitVersion(ForegroundProcessSupervisor $supervisor): string
    {
        $result = (new GitCommand(
            $supervisor,
            (new EnvironmentProjector())->project(['PATH']),
        ))->run($this->projectRoot, ['--version']);

        return $result->successful() ? trim($result->stdout) : 'unavailable';
    }

    /** @param callable(string): int $callback */
    private function withTask(?string $task, callable $callback): int
    {
        if ($task === null || trim($task) === '') {
            return $this->usage();
        }

        return $callback($task);
    }

    private function usage(): int
    {
        fwrite(STDERR, "Usage: agent-loop-runner <doctor|status|run|resume|cancel|cleanup> [TASK]\n");

        return ExitCode::USAGE;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): void
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode command output.', 0, $exception);
        }
        fwrite(STDOUT, $json . "\n");
    }

    private function failureCode(string $message): int
    {
        foreach ([
            'HOST_UNAVAILABLE' => ExitCode::HOST_UNAVAILABLE,
            'PROCESS_TIMEOUT' => ExitCode::PROCESS_TIMEOUT,
            'PROCESS_FAILED' => ExitCode::PROCESS_FAILED,
            'INVALID_STAGE_RESULT' => ExitCode::INVALID_STAGE_RESULT,
            'STALE_' => ExitCode::STALE_STATE,
            'TRANSITION_REJECTED' => ExitCode::TRANSITION_REJECTED,
            'WAITING_FOR_ATTENTION' => ExitCode::WAITING_FOR_ATTENTION,
        ] as $prefix => $code) {
            if (str_contains($message, $prefix)) {
                return $code;
            }
        }

        return ExitCode::INTERNAL;
    }
}
