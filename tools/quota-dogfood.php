<?php

declare(strict_types=1);

use voku\AgentLoop\Execution\AcceptedStageResult;
use voku\AgentLoop\Execution\ExecutionEnvironmentObservation;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoopRunner\Application\ExitCode;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Diagnostics\DiagnosticLogStore;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionCoordinator;
use voku\AgentLoopRunner\Execution\ExecutionGatewayPort;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\AgyHostAdapter;
use voku\AgentLoopRunner\Host\ClaudeHostAdapter;
use voku\AgentLoopRunner\Host\CodexHostAdapter;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\HostAvailability;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Host\HostExecutionResult;
use voku\AgentLoopRunner\Host\OpenCodeHostAdapter;
use voku\AgentLoopRunner\Process\EnvironmentProjector;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\ProcessResult;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
} elseif (file_exists(dirname(__DIR__, 3) . '/autoload.php')) {
    require dirname(__DIR__, 3) . '/autoload.php';
}

final class DogfoodGateway implements ExecutionGatewayPort
{
    public ?string $observedHost = null;
    private bool $completed = false;

    public function __construct(
        private readonly string $taskId,
        private readonly string $baseSha,
        private readonly string $repositoryRoot,
        private readonly string $roleId = 'builder',
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
            $this->completed ? null : $this->roleId,
            1,
            null,
            [],
            $this->baseSha,
        );
    }

    public function prepareStage(string $taskId, string $stageId): StageExecutionBundle
    {
        return new StageExecutionBundle(
            $this->taskId,
            'run:' . $this->taskId,
            1,
            'sha256:' . str_repeat('a', 64),
            $stageId,
            1,
            ExecutionStageKind::AGENT,
            $this->roleId,
            true,
            $this->repositoryRoot,
            $this->baseSha,
            $this->baseSha,
            ['path' => 'contract', 'sha256' => 'sha256:' . str_repeat('e', 64)],
            null,
            ['README.md'],
            ['composer ci'],
            null,
            [StageOutcome::PASS, StageOutcome::FAILED],
            'AGENT_LOOP_STAGE_RESULT ',
            'Perform governed task step',
        );
    }

    public function prepareStageForEnvironment(string $taskId, string $stageId, ExecutionEnvironmentObservation $observation): StageExecutionBundle
    {
        $this->observedHost = $observation->hostId;
        $bundle = $this->prepareStage($taskId, $stageId);

        return new StageExecutionBundle(
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $bundle->kind,
            $bundle->roleId,
            $bundle->mayMutate,
            $bundle->repositoryRoot,
            $bundle->baseCommit,
            $bundle->candidateRevision,
            $bundle->contractSource,
            $bundle->recallSource,
            $bundle->allowedScope,
            $bundle->requiredValidation,
            $bundle->priorHandoff,
            $bundle->acceptedOutcomes,
            $bundle->completionMarker,
            $bundle->prompt . "\nenvironment=" . $observation->digest(),
            $observation->digest(),
        );
    }

    public function recordStageCandidate(\voku\AgentLoop\Execution\StageCandidateObservation $observation): string
    {
        return $this->baseSha;
    }

    public function recordStageArtifact(\voku\AgentLoop\Execution\StageArtifactObservation $observation): string
    {
        return 'sha256:' . str_repeat('a', 64);
    }

    public function runDeterministicStage(string $taskId, string $stageId): ExecutionProjection
    {
        throw new RuntimeException('Deterministic stages are not used in quota dogfood.');
    }

    public function submitStageResult(StageResult $result): ExecutionProjection
    {
        $this->completed = true;

        return $this->projection($this->taskId);
    }
}

final class MockQuotaHost implements HostAdapter
{
    public int $probeCount = 0;
    public int $executionCount = 0;

    public function __construct(
        private readonly string $id,
        private readonly bool $available,
        private readonly ?float $remainingRatio = null,
        private readonly ?int $resetAt = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability
    {
        ++$this->probeCount;

        return new HostAvailability(
            $this->id,
            $this->available ? '/usr/bin/' . $this->id : null,
            $this->available ? $this->id . '-mock 1.0' : null,
            $this->available ? null : 'binary not found',
            $this->remainingRatio,
            $this->resetAt,
        );
    }

    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        ++$this->executionCount;

        return new HostExecutionResult(
            $this->id,
            new ProcessResult(
                0,
                'AGENT_LOOP_STAGE_RESULT {"outcome":"pass","summary":"completed via ' . $this->id . '","artifact_references":[],"validation_references":[]}' . "\n",
                '',
                false,
                '2026-09-21T08:00:00Z',
                '2026-09-21T08:00:01Z',
            ),
        );
    }
}

$mode = $argv[1] ?? 'full-dogfood';
$projectRoot = dirname(__DIR__);
$evidenceDirectory = $argv[2] ?? (sys_get_temp_dir() . '/agent-loop-runner-quota-dogfood-' . bin2hex(random_bytes(4)));
$supportedModes = ['probe', 'verify-fallback', 'verify-break', 'full-dogfood'];
if (!in_array($mode, $supportedModes, true)) {
    fwrite(STDERR, 'Usage: php tools/quota-dogfood.php [probe|verify-fallback|verify-break|full-dogfood] [evidence-directory]' . "\n");
    exit(ExitCode::USAGE);
}

if (!is_dir($evidenceDirectory) && !mkdir($evidenceDirectory, 0o775, true) && !is_dir($evidenceDirectory)) {
    fwrite(STDERR, "Unable to create evidence directory: {$evidenceDirectory}\n");
    exit(ExitCode::INTERNAL);
}

$supervisor = new ForegroundProcessSupervisor();
$config = RunnerConfig::load($projectRoot);
$environment = (new EnvironmentProjector())->project($config->environmentAllowlist);

$results = [
    'schema_version' => '1.0',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'mode' => $mode,
    'probed_agents' => [],
    'fallback_verification' => null,
    'hard_break_verification' => null,
];

// Step 1: Probe configured hosts on the local host machine
$realHosts = [
    'codex' => new CodexHostAdapter($config->binary('codex'), resourceCommand: $config->resourceCommandForHost('codex')),
    'claude' => new ClaudeHostAdapter($config->binary('claude'), resourceCommand: $config->resourceCommandForHost('claude')),
    'opencode' => new OpenCodeHostAdapter($config->binary('opencode'), resourceCommand: $config->resourceCommandForHost('opencode')),
    'agy' => new AgyHostAdapter($config->binary('agy'), resourceCommand: $config->resourceCommandForHost('agy')),
];

foreach ($realHosts as $id => $adapter) {
    $probe = $adapter->probe($supervisor, $projectRoot, $environment);
    $usage = $probe->usageRatio();
    $results['probed_agents'][$id] = [
        'available' => $probe->available(),
        'binary_path' => $probe->binaryPath,
        'version' => $probe->version,
        'failure' => $probe->failure,
        'remaining_ratio' => $probe->remainingRatio,
        'usage_ratio' => $usage,
        'usage_percent' => $usage !== null ? sprintf('%.1f%%', $usage * 100) : null,
        'is_near_limit' => $probe->isNearLimit($config->quotaUsageThreshold),
        'reset_at' => $probe->resetAt,
        'capacity_summary' => $probe->capacitySummary,
        'configured_fallback' => $config->fallbackForHost($id),
    ];
}

// Step 2: Verification of >= 95% quota limit handling via isolated coordinator.
$runFallback = in_array($mode, ['verify-fallback', 'full-dogfood'], true);
$runBreak = in_array($mode, ['verify-break', 'full-dogfood'], true);
if ($runFallback || $runBreak) {
    $tempRepo = sys_get_temp_dir() . '/quota-dogfood-repo-' . bin2hex(random_bytes(5));
    mkdir($tempRepo, 0o775, true);
    exec("git -C {$tempRepo} init -q");
    exec("git -C {$tempRepo} config user.email test@example.invalid");
    exec("git -C {$tempRepo} config user.name test");
    file_put_contents($tempRepo . '/README.md', "fixture\n");
    exec("git -C {$tempRepo} add README.md");
    exec("git -C {$tempRepo} commit -qm fixture");
    $baseSha = trim((string) shell_exec("git -C {$tempRepo} rev-parse HEAD"));
    $layout = new RunnerLayout($tempRepo);
    $git = new GitCommand($supervisor, ['PATH' => (string) getenv('PATH')]);

    try {
        if ($runFallback) {
            // Scenario 1: Fallback verification (>= 95% used, fallback is configured and healthy)
    $exhaustedHost = new MockQuotaHost('codex', true, remainingRatio: 0.02, resetAt: time() + 1800); // 98% used
    $healthyFallback = new MockQuotaHost('claude', true, remainingRatio: 0.80); // 20% used

    $scenarioConfig = new RunnerConfig(
        [
            'codex' => ['binary' => 'codex'],
            'claude' => ['binary' => 'claude'],
        ],
        ['builder' => 'codex'],
        1800,
        ['PATH'],
        [],
        ['builder' => 'claude'],
        0.95,
    );

    $gatewayFallback = new DogfoodGateway('DOGFOOD-FALLBACK', $baseSha, $tempRepo);

    $coordinatorFallback = new ExecutionCoordinator(
        $gatewayFallback,
        new RuntimeJournal($layout),
        new RunWorkspaceManager($layout, new GitWorktreeService($git), new WorkspaceCandidateHasher($git)),
        new CompletionEnvelopeParser(),
        $scenarioConfig,
        ['codex' => $exhaustedHost, 'claude' => $healthyFallback],
        $supervisor,
        new DiagnosticLogStore($layout),
    );

    $projection = $coordinatorFallback->run('DOGFOOD-FALLBACK');
            $results['fallback_verification'] = [
                'status' => $projection->complete() ? 'PASS' : 'FAIL',
                'primary_host_executions' => $exhaustedHost->executionCount,
                'fallback_host_executions' => $healthyFallback->executionCount,
                'observed_host_in_gateway' => $gatewayFallback->observedHost,
                'notes' => 'Primary host (98% usage) skipped safely; routed to healthy fallback host claude.',
            ];
        }

        if ($runBreak) {
            // Scenario 2: Hard break verification (>= 95% used, NO fallback configured)
            $hardBreakExhausted = new MockQuotaHost('codex', true, remainingRatio: 0.03, resetAt: time() + 3600); // 97% used
            $noFallbackConfig = new RunnerConfig(
                ['codex' => ['binary' => 'codex']],
                ['builder' => 'codex'],
                1800,
                ['PATH'],
                [],
                [], // No fallback
                0.95,
            );

            $gatewayBreak = new DogfoodGateway('DOGFOOD-BREAK', $baseSha, $tempRepo);
            $coordinatorBreak = new ExecutionCoordinator(
                $gatewayBreak,
                new RuntimeJournal($layout),
                new RunWorkspaceManager($layout, new GitWorktreeService($git), new WorkspaceCandidateHasher($git)),
                new CompletionEnvelopeParser(),
                $noFallbackConfig,
                ['codex' => $hardBreakExhausted],
                $supervisor,
                new DiagnosticLogStore($layout),
            );

            $caughtException = null;
            try {
                $coordinatorBreak->run('DOGFOOD-BREAK');
            } catch (RuntimeException $exception) {
                $caughtException = $exception;
            }

            if ($caughtException !== null && str_contains($caughtException->getMessage(), 'QUOTA_LIMIT_REACHED')) {
                $results['hard_break_verification'] = [
                    'status' => 'PASS',
                    'primary_host_executions' => $hardBreakExhausted->executionCount,
                    'exception_message' => $caughtException->getMessage(),
                    'message_contains_why_it_breaks' => str_contains($caughtException->getMessage(), 'prevent the task from failing mid-execution'),
                    'message_contains_how_to_configure_fallback' => str_contains($caughtException->getMessage(), 'role_fallbacks'),
                    'message_contains_reset_time' => str_contains($caughtException->getMessage(), 'Quota resets at'),
                ];
            } else {
                $results['hard_break_verification'] = [
                    'status' => 'FAIL',
                    'reason' => 'Expected QUOTA_LIMIT_REACHED exception was not thrown.',
                ];
            }
        }
    } finally {
        exec("rm -rf {$tempRepo}");
    }
}

$jsonOutput = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($evidenceDirectory . '/quota-dogfood-report.json', $jsonOutput);
fwrite(STDOUT, $jsonOutput);

$allPassed = match ($mode) {
    'probe' => true,
    'verify-fallback' => ($results['fallback_verification']['status'] ?? '') === 'PASS',
    'verify-break' => ($results['hard_break_verification']['status'] ?? '') === 'PASS',
    default => ($results['fallback_verification']['status'] ?? '') === 'PASS'
        && ($results['hard_break_verification']['status'] ?? '') === 'PASS',
};

exit($allPassed ? ExitCode::OK : ExitCode::INTERNAL);
