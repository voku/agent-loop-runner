<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoopRunner\Config\RunnerConfig;
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
            $checks['host_' . $id] = $availability->available() ? ($availability->version ?? 'available') : 'unavailable';
        }
        $this->json($checks);

        return in_array('missing', $checks, true) ? ExitCode::TRANSITION_REJECTED : ExitCode::OK;
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
            'codex' => new CodexHostAdapter($config->binary('codex')),
            'claude' => new ClaudeHostAdapter($config->binary('claude')),
            'opencode' => new OpenCodeHostAdapter($config->binary('opencode')),
            'agy' => new AgyHostAdapter($config->binary('agy')),
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
