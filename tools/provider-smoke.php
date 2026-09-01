<?php

declare(strict_types=1);

use voku\AgentLoopRunner\Application\ExitCode;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\AgyHostAdapter;
use voku\AgentLoopRunner\Host\ClaudeHostAdapter;
use voku\AgentLoopRunner\Host\CodexHostAdapter;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Host\OpenCodeHostAdapter;
use voku\AgentLoopRunner\Process\EnvironmentProjector;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
} elseif (file_exists(dirname(__DIR__, 3) . '/autoload.php')) {
    require dirname(__DIR__, 3) . '/autoload.php';
}

const PROVIDER_SMOKE_MARKER = 'PROVIDER_SMOKE_OK';

/** @param array<string, mixed> $payload */
function emit(array $payload): void
{
    try {
        fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } catch (\JsonException $exception) {
        throw new \RuntimeException('Unable to encode provider smoke result.', 0, $exception);
    }
}

/** @return HostAdapter */
function adapter(string $hostId, RunnerConfig $config): HostAdapter
{
    return match ($hostId) {
        'codex' => new CodexHostAdapter($config->binary('codex')),
        'claude' => new ClaudeHostAdapter($config->binary('claude')),
        'opencode' => new OpenCodeHostAdapter($config->binary('opencode')),
        'agy' => new AgyHostAdapter($config->binary('agy')),
        default => throw new \RuntimeException('Unsupported provider smoke host: ' . $hostId),
    };
}

$hostId = $argv[1] ?? '';
$workingDirectory = $argv[2] ?? '';
if (!in_array($hostId, ['codex', 'claude', 'opencode', 'agy'], true) || $workingDirectory === '') {
    fwrite(STDERR, "Usage: php tools/provider-smoke.php <codex|claude|opencode|agy> <clean-git-working-directory>\n");
    exit(ExitCode::USAGE);
}

$workingDirectory = realpath($workingDirectory) ?: '';
if ($workingDirectory === '' || !is_dir($workingDirectory)) {
    fwrite(STDERR, "Provider smoke working directory does not exist.\n");
    exit(ExitCode::USAGE);
}

$projectRoot = is_file(dirname(__DIR__) . '/composer.json') ? dirname(__DIR__) : dirname(__DIR__, 3);
$supervisor = new ForegroundProcessSupervisor();
$config = RunnerConfig::load($projectRoot);
$environment = (new EnvironmentProjector())->project($config->environmentAllowlist);
$git = new GitCommand($supervisor, $environment);

try {
    $before = $git->requireSuccess($workingDirectory, ['status', '--porcelain=v1', '-z'])->stdout;
    if ($before !== '') {
        throw new \RuntimeException('Provider smoke requires a clean Git working directory.');
    }

    $host = adapter($hostId, $config);
    $availability = $host->probe($supervisor, $workingDirectory, $environment);
    if (!$availability->available()) {
        $failure = $availability->failure ?? '';
        emit([
            'schema_version' => '1.0',
            'host' => $hostId,
            'status' => 'HOST_UNAVAILABLE',
            'version' => $availability->version,
            'failure_bytes' => strlen($failure),
            'failure_sha256' => hash('sha256', $failure),
        ]);
        exit(ExitCode::HOST_UNAVAILABLE);
    }

    $result = $host->execute(new HostExecutionRequest(
        'provider-smoke',
        $workingDirectory,
        'Reply with exactly ' . PROVIDER_SMOKE_MARKER . '. Do not create, modify, or delete files.',
        $environment,
        min($config->timeoutSeconds, 120),
    ), $supervisor)->process;

    $after = $git->requireSuccess($workingDirectory, ['status', '--porcelain=v1', '-z'])->stdout;
    $markerObserved = str_contains($result->stdout, PROVIDER_SMOKE_MARKER);
    $status = $result->timedOut
        ? 'PROCESS_TIMEOUT'
        : (!$result->successful() || !$markerObserved || $after !== '' ? 'PROCESS_FAILED' : 'PASS');

    emit([
        'schema_version' => '1.0',
        'host' => $hostId,
        'status' => $status,
        'version' => $availability->version,
        'process_exit_code' => $result->exitCode,
        'timed_out' => $result->timedOut,
        'marker_observed' => $markerObserved,
        'working_tree_clean' => $after === '',
        'stdout_bytes' => strlen($result->stdout),
        'stderr_bytes' => strlen($result->stderr),
        'stdout_sha256' => hash('sha256', $result->stdout),
        'stderr_sha256' => hash('sha256', $result->stderr),
    ]);

    exit(match ($status) {
        'PASS' => ExitCode::OK,
        'PROCESS_TIMEOUT' => ExitCode::PROCESS_TIMEOUT,
        default => ExitCode::PROCESS_FAILED,
    });
} catch (\Throwable $exception) {
    $message = $exception->getMessage();
    emit([
        'schema_version' => '1.0',
        'host' => $hostId,
        'status' => 'INTERNAL',
        'error_class' => $exception::class,
        'error_bytes' => strlen($message),
        'error_sha256' => hash('sha256', $message),
    ]);
    exit(ExitCode::INTERNAL);
}
