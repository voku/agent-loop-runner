<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use RuntimeException;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Diagnostics\DiagnosticLogStore;
use voku\AgentLoopRunner\Execution\AgentLoopExecutionGateway;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionCoordinator;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\AgyHostAdapter;
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

/**
 * Typed application boundary for non-CLI Runner adapters.
 *
 * Workflow authority always comes from agent-loop's ExecutionGateway. Runner
 * journal/process data remains observation only and is never promoted here.
 */
final readonly class RunnerControlService
{
    public function __construct(private string $projectRoot)
    {
    }

    public function status(string $taskId): RunnerStatus
    {
        $authority = (new ExecutionGateway($this->projectRoot))->projection($taskId);
        $observation = (new RuntimeJournal(new RunnerLayout($this->projectRoot)))->load($taskId);

        return new RunnerStatus($authority, $observation);
    }

    public function run(string $taskId): ExecutionProjection
    {
        return $this->coordinator()->run($taskId);
    }

    public function resume(string $taskId): ExecutionProjection
    {
        return $this->coordinator()->resume($taskId);
    }

    public function cancel(string $taskId): RuntimeAttempt
    {
        $journal = new RuntimeJournal(new RunnerLayout($this->projectRoot));
        $attempt = $journal->load($taskId);
        if ($attempt === null || $attempt->status !== AttemptStatus::ProcessStarted) {
            throw new RuntimeException('PROCESS_FAILED: no owned active process is recorded.');
        }

        return $journal->cancel($attempt, static function (RuntimeAttempt $current): bool {
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
    }

    public function cleanup(string $taskId): void
    {
        $layout = new RunnerLayout($this->projectRoot);
        $lock = RunExecutionLock::acquire($layout, $taskId);
        try {
            $journal = new RuntimeJournal($layout);
            $attempt = $journal->load($taskId);
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

            // The workspace is gone, so the record describing it must go too.
            // Leaving it behind pins the task to a retired run/Contract revision
            // and makes every later run/resume fail STALE_RUN, with no recovery
            // path even after the owner approved a new Contract revision.
            $journal->forget($attempt->taskId);
        } finally {
            $lock->release();
        }
    }

    private function coordinator(): ExecutionCoordinator
    {
        $config = RunnerConfig::load($this->projectRoot);
        $layout = new RunnerLayout($this->projectRoot);
        $supervisor = new ForegroundProcessSupervisor();
        $git = new GitCommand($supervisor, (new EnvironmentProjector())->project(['PATH', 'HOME']));

        return new ExecutionCoordinator(
            new AgentLoopExecutionGateway(new ExecutionGateway($this->projectRoot)),
            new RuntimeJournal($layout),
            new RunWorkspaceManager(
                $layout,
                new GitWorktreeService($git),
                new WorkspaceCandidateHasher($git),
            ),
            new CompletionEnvelopeParser(),
            $config,
            $this->hosts($config),
            $supervisor,
            new DiagnosticLogStore($layout),
        );
    }

    /** @return array<string, HostAdapter> */
    private function hosts(RunnerConfig $config): array
    {
        return [
            'codex' => new CodexHostAdapter($config->binary('codex')),
            'claude' => new ClaudeHostAdapter($config->binary('claude')),
            'opencode' => new OpenCodeHostAdapter($config->binary('opencode')),
            'agy' => new AgyHostAdapter($config->binary('agy')),
        ];
    }
}
