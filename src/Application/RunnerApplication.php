<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Diagnostics\ValidationCommandInspector;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\AgyHostAdapter;
use voku\AgentLoopRunner\Host\ClaudeHostAdapter;
use voku\AgentLoopRunner\Host\CodexHostAdapter;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\OpenCodeHostAdapter;
use voku\AgentLoopRunner\Process\EnvironmentProjector;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;

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
                'doctor' => $this->doctor($task !== null && trim($task) !== '' ? $task : null),
                'status' => $this->withTask($task, fn (string $id): int => $this->status($id)),
                'run', 'resume' => $this->withTask($task, fn (string $id): int => $this->execute($id, $command)),
                'cancel' => $this->withTask($task, fn (string $id): int => $this->cancel($id)),
                'abandon' => $this->withTask($task, fn (string $id): int => $this->abandon($id, array_slice($arguments, 2))),
                'cleanup' => $this->withTask($task, fn (string $id): int => $this->cleanup($id)),
                default => $this->usage(),
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n");

            return $this->failureCode($exception->getMessage());
        }
    }

    private function doctor(?string $taskId): int
    {
        $supervisor = new ForegroundProcessSupervisor();
        $config = RunnerConfig::load($this->projectRoot);
        $environment = (new EnvironmentProjector())->project($config->environmentAllowlist);
        $checks = [
            'php' => PHP_VERSION,
            'git' => $this->gitVersion($supervisor),
            'execution_gateway' => class_exists(ExecutionGateway::class) ? 'available' : 'missing',
        ];
        $hostAvailable = [];
        foreach ($this->hosts($config) as $id => $host) {
            $availability = $host->probe($supervisor, $this->projectRoot, $environment);
            $hostAvailable[$id] = $availability->available();
            $checks['host_' . $id] = $availability->available() ? ($availability->version ?? 'available') : 'unavailable';
        }
        $roles = [];
        foreach ($config->roles as $roleId => $hostId) {
            $roles[$roleId] = $hostId . ': ' . (($hostAvailable[$hostId] ?? false) ? 'available' : 'unavailable');
        }
        $checks['roles'] = $roles;
        $exit = in_array('missing', $checks, true) ? ExitCode::TRANSITION_REJECTED : ExitCode::OK;
        if ($taskId !== null) {
            $checks['validation'] = $this->validationAdvisory($supervisor, $taskId);
        }
        $this->json($checks);

        return $exit;
    }

    /**
     * Read-only, advisory view of the current stage's declared validation commands.
     * Never submits a result, never writes, and never influences the exit code.
     *
     * @return array<string, mixed>
     */
    private function validationAdvisory(ForegroundProcessSupervisor $supervisor, string $taskId): array
    {
        $advisory = ['stage' => null, 'commands' => [], 'note' => ValidationCommandInspector::COMPLETENESS_NOTE];
        try {
            $gateway = new ExecutionGateway($this->projectRoot);
            $stageId = $gateway->projection($taskId)->currentStageId;
            if ($stageId === null) {
                $advisory['reason'] = 'no current stage';

                return $advisory;
            }
            $advisory['stage'] = $stageId;
            $bundle = $gateway->prepareStage($taskId, $stageId);
            $commands = $bundle->requiredValidation;
            $baseCommit = $bundle->baseCommit;
            if ($baseCommit === null) {
                $advisory['commands'] = ValidationCommandInspector::notInspectable($commands, 'Contract base commit is unknown');

                return $advisory;
            }
            $git = new GitCommand($supervisor, (new EnvironmentProjector())->project(['PATH']));
            $inspector = new ValidationCommandInspector(function (string $path) use ($git, $baseCommit): bool {
                $result = $git->run($this->projectRoot, ['ls-tree', '-r', '--name-only', $baseCommit, '--', $path]);
                if (!$result->successful()) {
                    throw new RuntimeException('git ls-tree failed');
                }

                return trim($result->stdout) !== '';
            });
            $advisory['commands'] = $inspector->inspect($commands);
        } catch (Throwable $exception) {
            $advisory['reason'] = 'validation commands could not be read: ' . $exception->getMessage();
        }

        return $advisory;
    }

    private function status(string $taskId): int
    {
        $this->json($this->controls()->status($taskId)->toArray());

        return ExitCode::OK;
    }

    private function execute(string $taskId, string $command): int
    {
        $controls = $this->controls();
        $projection = $command === 'run' ? $controls->run($taskId) : $controls->resume($taskId);
        $this->json([
            'task_id' => $projection->taskId,
            'complete' => $projection->complete(),
            'current_stage_id' => $projection->currentStageId,
        ]);

        return ExitCode::OK;
    }

    private function cancel(string $taskId): int
    {
        $this->controls()->cancel($taskId);

        return ExitCode::OK;
    }

    /** @param list<string> $options */
    private function abandon(string $taskId, array $options): int
    {
        $by = null;
        $reason = null;
        for ($i = 0, $count = count($options); $i < $count; $i += 2) {
            $value = $options[$i + 1] ?? null;
            if ($value === null || trim($value) === '') {
                return $this->abandonUsage();
            }
            if ($options[$i] === '--by') {
                $by = $value;
            } elseif ($options[$i] === '--reason') {
                $reason = $value;
            } else {
                return $this->abandonUsage();
            }
        }
        if ($by === null || $reason === null) {
            return $this->abandonUsage();
        }

        $attempt = $this->controls()->abandon($taskId, $by, $reason);
        $this->json([
            'task_id' => $attempt->taskId,
            'stage_id' => $attempt->stageId,
            'attempt' => $attempt->attempt,
            'status' => $attempt->status->value,
            'abandoned_by' => $attempt->process['abandoned_by'] ?? null,
            'abandoned_reason' => $attempt->process['abandoned_reason'] ?? null,
            'abandoned_at' => $attempt->process['abandoned_at'] ?? null,
        ]);

        return ExitCode::OK;
    }

    private function abandonUsage(): int
    {
        fwrite(STDERR, "Usage: agent-loop-runner abandon TASK --by <actor> --reason <text>\n");

        return ExitCode::USAGE;
    }

    private function cleanup(string $taskId): int
    {
        $this->controls()->cleanup($taskId);

        return ExitCode::OK;
    }

    private function controls(): RunnerControlService
    {
        return new RunnerControlService($this->projectRoot);
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

    private function gitVersion(ForegroundProcessSupervisor $supervisor): string
    {
        $result = (new GitCommand($supervisor, (new EnvironmentProjector())->project(['PATH'])))
            ->run($this->projectRoot, ['--version']);

        return $result->successful() ? trim($result->stdout) : 'unavailable';
    }

    /** @param callable(string): int $callback */
    private function withTask(?string $taskId, callable $callback): int
    {
        if ($taskId === null || trim($taskId) === '') {
            return $this->usage();
        }

        return $callback($taskId);
    }

    private function usage(): int
    {
        fwrite(STDERR, "Usage: agent-loop-runner <doctor|status|run|resume|cancel|abandon|cleanup> [TASK]\n");

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
            'QUOTA_LIMIT_REACHED' => ExitCode::HOST_UNAVAILABLE,
            'PROCESS_TIMEOUT' => ExitCode::PROCESS_TIMEOUT,
            'PROCESS_FAILED' => ExitCode::PROCESS_FAILED,
            'INVALID_STAGE_RESULT' => ExitCode::INVALID_STAGE_RESULT,
            'STALE_' => ExitCode::STALE_STATE,
            'TRANSITION_REJECTED' => ExitCode::TRANSITION_REJECTED,
            'WAITING_FOR_ATTENTION' => ExitCode::WAITING_FOR_ATTENTION,
            'PROCESS_CANCELLED' => ExitCode::PROCESS_CANCELLED,
        ] as $prefix => $code) {
            if (str_contains($message, $prefix)) {
                return $code;
            }
        }

        return ExitCode::INTERNAL;
    }
}
