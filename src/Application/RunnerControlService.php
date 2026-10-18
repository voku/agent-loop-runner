<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use RuntimeException;
use voku\AgentLoop\Execution\CurrentExecutionStageReader;
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
use voku\AgentLoopRunner\Process\CancellableProcess;
use voku\AgentLoopRunner\Process\ProcessIdentity;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
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
    /**
     * @param array<string, HostAdapter>|null $hosts
     * @param array<string, string>|null $environment
     */
    public function __construct(
        private string $projectRoot,
        private ?RequiredHostPreflight $preflight = null,
        private ?CurrentExecutionStageReader $stageReader = null,
        private ?ExecutionGateway $executionGateway = null,
        private ?RuntimeJournal $journal = null,
        private ?RunnerConfig $config = null,
        private ?array $hosts = null,
        private ?ProcessSupervisor $supervisor = null,
        private ?array $environment = null,
    ) {
    }

    public function status(string $taskId): RunnerStatus
    {
        $authority = ($this->executionGateway ?? new ExecutionGateway($this->projectRoot))->projection($taskId);
        $observation = ($this->journal ?? new RuntimeJournal(new RunnerLayout($this->projectRoot)))->load($taskId);
        $stage = ($this->stageReader ?? new CurrentExecutionStageReader($this->projectRoot))->read($taskId);

        $preflight = $this->preflight ?? $this->defaultPreflight();
        $requiredHost = $preflight->observe($authority, $stage, $this->projectRoot);

        return new RunnerStatus($authority, $observation, $requiredHost);
    }

    private function defaultPreflight(): RequiredHostPreflight
    {
        $config = $this->config ?? RunnerConfig::load($this->projectRoot);
        $supervisor = $this->supervisor ?? new ForegroundProcessSupervisor();
        $environment = $this->environment ?? (new EnvironmentProjector())->project($config->environmentAllowlist);

        return new RequiredHostPreflight($config, $this->hosts ?? $this->hosts($config), $supervisor, $environment);
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
            $problem = CancellableProcess::problem($current->process);
            $pid = $current->process['pid'] ?? null;
            if ($problem !== null || !is_int($pid)) {
                throw new RuntimeException('PROCESS_FAILED: ' . ($problem ?? 'owned process identity is stale.'));
            }

            return posix_kill(-$pid, defined('SIGTERM') ? SIGTERM : 15);
        });
    }

    /**
     * Operator evidence that a recorded provider process no longer exists (for example after the Runner itself
     * was killed). Moves the stale `process_started` attempt to the terminal Cancelled status so `cleanup` can
     * retire it. It never starts a provider, never submits a result and never talks to agent-loop.
     */
    public function abandon(string $taskId, string $by, string $reason): RuntimeAttempt
    {
        $layout = new RunnerLayout($this->projectRoot);
        $lock = RunExecutionLock::acquire($layout, $taskId);
        try {
            $journal = new RuntimeJournal($layout);
            $attempt = $journal->load($taskId);
            if ($attempt === null || $attempt->status !== AttemptStatus::ProcessStarted) {
                throw new RuntimeException('PROCESS_FAILED: no owned active process is recorded.');
            }

            return $journal->abandon($attempt, $by, $reason, self::recordedProcessIsProvablyGone(...));
        } finally {
            $lock->release();
        }
    }

    /**
     * True only when the recorded identity is provably gone: the pid no longer exists, or it now belongs to a
     * process with a different fingerprint (pid reuse), and nothing is left in the provider's process group
     * (the provider runs as its own group leader, so a child may outlive it). Anything that cannot be
     * inspected fails closed.
     */
    private static function recordedProcessIsProvablyGone(RuntimeAttempt $current): bool
    {
        $pid = $current->process['pid'] ?? null;
        $fingerprint = $current->process['process_fingerprint'] ?? null;
        if (!is_int($pid) || !is_string($fingerprint)) {
            throw new RuntimeException('PROCESS_FAILED: recorded process identity is incomplete; cannot prove it is gone.');
        }
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_kill') || !function_exists('posix_get_last_error')) {
            throw new RuntimeException('PROCESS_FAILED: process inspection is unavailable on this platform.');
        }
        if (self::signalZeroReachesSomething($pid)) {
            $observed = ProcessIdentity::fingerprint($pid);
            if ($observed === null) {
                throw new RuntimeException('PROCESS_FAILED: pid exists but its identity cannot be inspected; cannot prove it is gone.');
            }
            if (hash_equals($fingerprint, $observed)) {
                return false;
            }
        }
        if (self::signalZeroReachesSomething(-$pid)) {
            throw new RuntimeException('PROCESS_FAILED: the recorded process group still has live members; stop them first (cancel only works while the group leader is alive).');
        }

        return true;
    }

    /** Signal 0 only probes: EPERM still means "something is there". */
    private static function signalZeroReachesSomething(int $target): bool
    {
        return posix_kill($target, 0) || posix_get_last_error() === 1;
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
            $abandonableFailedProcess = $attempt->status === AttemptStatus::ProcessExited
                && $attempt->stageResult === null
                && $attempt->completionEnvelope === null;
            if (!$abandonableFailedProcess
                && !in_array($attempt->status, [AttemptStatus::ReconciledAccepted, AttemptStatus::Cancelled], true)) {
                throw new RuntimeException('STALE_WORKSPACE: workspace has unreconciled evidence.');
            }

            $supervisor = new ForegroundProcessSupervisor();
            $git = new GitCommand($supervisor, (new EnvironmentProjector())->project(['PATH', 'HOME']));
            $manager = new RunWorkspaceManager(
                $layout,
                new GitWorktreeService($git),
                new WorkspaceCandidateHasher($git),
            );
            // Failed provider attempts are abandonable only while the worktree is
            // still clean. GitWorktreeService::remove() refuses dirty worktrees,
            // so any candidate evidence remains fail-closed here.
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
            'codex' => new CodexHostAdapter($config->binary('codex'), resourceCommand: $config->resourceCommandForHost('codex')),
            'claude' => new ClaudeHostAdapter($config->binary('claude'), resourceCommand: $config->resourceCommandForHost('claude')),
            'opencode' => new OpenCodeHostAdapter($config->binary('opencode'), resourceCommand: $config->resourceCommandForHost('opencode')),
            'agy' => new AgyHostAdapter($config->binary('agy'), resourceCommand: $config->resourceCommandForHost('agy')),
        ];
    }
}
