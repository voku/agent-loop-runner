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

/** @param array<string, mixed> $payload */
function emitPremiseDogfoodResult(array $payload, string $evidenceDirectory): void
{
    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (JsonException $exception) {
        throw new RuntimeException('Unable to encode premise dogfood result.', 0, $exception);
    }

    if (file_put_contents($evidenceDirectory . '/result.json', $json) === false) {
        throw new RuntimeException('Unable to write premise dogfood result.');
    }

    fwrite(STDOUT, $json);
}

function premiseDogfoodAdapter(string $hostId, RunnerConfig $config): HostAdapter
{
    return match ($hostId) {
        'codex' => new CodexHostAdapter($config->binary('codex')),
        'claude' => new ClaudeHostAdapter($config->binary('claude')),
        'opencode' => new OpenCodeHostAdapter($config->binary('opencode')),
        'agy' => new AgyHostAdapter($config->binary('agy')),
        default => throw new RuntimeException('Unsupported premise dogfood host: ' . $hostId),
    };
}

function writePremiseDogfoodEvidence(string $path, string $content): void
{
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Unable to write premise dogfood evidence: ' . $path);
    }
}

$hostId = $argv[1] ?? '';
$workingDirectory = $argv[2] ?? '';
$promptPath = $argv[3] ?? '';
$evidenceDirectory = $argv[4] ?? '';

if (!in_array($hostId, ['codex', 'claude', 'opencode', 'agy'], true)
    || $workingDirectory === ''
    || $promptPath === ''
    || $evidenceDirectory === ''
) {
    fwrite(STDERR, "Usage: php tools/premise-dogfood.php <codex|claude|opencode|agy> <clean-git-working-directory> <prompt-file> <evidence-directory>\n");
    exit(ExitCode::USAGE);
}

$workingDirectory = realpath($workingDirectory) ?: '';
$promptPath = realpath($promptPath) ?: '';
if ($workingDirectory === '' || !is_dir($workingDirectory) || $promptPath === '' || !is_file($promptPath)) {
    fwrite(STDERR, "Premise dogfood requires an existing Git working directory and prompt file.\n");
    exit(ExitCode::USAGE);
}

if (!is_dir($evidenceDirectory) && !mkdir($evidenceDirectory, 0o775, true) && !is_dir($evidenceDirectory)) {
    fwrite(STDERR, "Unable to create premise dogfood evidence directory.\n");
    exit(ExitCode::INTERNAL);
}
$evidenceDirectory = realpath($evidenceDirectory) ?: '';
if ($evidenceDirectory === '') {
    fwrite(STDERR, "Unable to resolve premise dogfood evidence directory.\n");
    exit(ExitCode::INTERNAL);
}

$prompt = file_get_contents($promptPath);
if (!is_string($prompt) || trim($prompt) === '') {
    fwrite(STDERR, "Premise dogfood prompt must be non-empty.\n");
    exit(ExitCode::USAGE);
}

$projectRoot = is_file(dirname(__DIR__) . '/composer.json') ? dirname(__DIR__) : dirname(__DIR__, 3);
$supervisor = new ForegroundProcessSupervisor();
$config = RunnerConfig::load($projectRoot);
$environment = (new EnvironmentProjector())->project($config->environmentAllowlist);
$git = new GitCommand($supervisor, $environment);

try {
    $beforeStatus = $git->requireSuccess($workingDirectory, ['status', '--porcelain=v1', '-z'])->stdout;
    if ($beforeStatus !== '') {
        throw new RuntimeException('Premise dogfood requires a clean Git working directory.');
    }
    $baseSha = trim($git->requireSuccess($workingDirectory, ['rev-parse', 'HEAD'])->stdout);

    $host = premiseDogfoodAdapter($hostId, $config);
    $availability = $host->probe($supervisor, $workingDirectory, $environment);
    if (!$availability->available()) {
        $failure = $availability->failure ?? '';
        emitPremiseDogfoodResult([
            'schema_version' => '1.0',
            'host' => $hostId,
            'status' => 'HOST_UNAVAILABLE',
            'version' => $availability->version,
            'base_sha' => $baseSha,
            'prompt_sha256' => hash('sha256', $prompt),
            'failure_bytes' => strlen($failure),
            'failure_sha256' => hash('sha256', $failure),
        ], $evidenceDirectory);
        exit(ExitCode::HOST_UNAVAILABLE);
    }

    $execution = $host->execute(new HostExecutionRequest(
        'agent-loop-342-premise-dogfood',
        $workingDirectory,
        $prompt,
        $environment,
        min($config->timeoutSeconds, 1800),
    ), $supervisor)->process;

    writePremiseDogfoodEvidence($evidenceDirectory . '/stdout.txt', $execution->stdout);
    writePremiseDogfoodEvidence($evidenceDirectory . '/stderr.txt', $execution->stderr);

    $afterStatus = $git->requireSuccess($workingDirectory, ['status', '--porcelain=v1', '-z'])->stdout;
    $afterSha = trim($git->requireSuccess($workingDirectory, ['rev-parse', 'HEAD'])->stdout);
    $status = $execution->timedOut
        ? 'PROCESS_TIMEOUT'
        : ($execution->successful() ? 'PROCESS_COMPLETED' : 'PROCESS_FAILED');

    emitPremiseDogfoodResult([
        'schema_version' => '1.0',
        'host' => $hostId,
        'status' => $status,
        'version' => $availability->version,
        'base_sha' => $baseSha,
        'after_sha' => $afterSha,
        'head_changed' => $afterSha !== $baseSha,
        'working_tree_clean' => $afterStatus === '',
        'working_tree_status_bytes' => strlen($afterStatus),
        'working_tree_status_sha256' => hash('sha256', $afterStatus),
        'prompt_sha256' => hash('sha256', $prompt),
        'process_exit_code' => $execution->exitCode,
        'timed_out' => $execution->timedOut,
        'stdout_bytes' => strlen($execution->stdout),
        'stderr_bytes' => strlen($execution->stderr),
        'stdout_sha256' => hash('sha256', $execution->stdout),
        'stderr_sha256' => hash('sha256', $execution->stderr),
    ], $evidenceDirectory);

    exit(match ($status) {
        'PROCESS_COMPLETED' => ExitCode::OK,
        'PROCESS_TIMEOUT' => ExitCode::PROCESS_TIMEOUT,
        default => ExitCode::PROCESS_FAILED,
    });
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    emitPremiseDogfoodResult([
        'schema_version' => '1.0',
        'host' => $hostId,
        'status' => 'INTERNAL',
        'error_class' => $exception::class,
        'error_bytes' => strlen($message),
        'error_sha256' => hash('sha256', $message),
    ], $evidenceDirectory);
    exit(ExitCode::INTERNAL);
}
