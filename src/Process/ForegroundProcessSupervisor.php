<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

final readonly class ForegroundProcessSupervisor implements ProcessSupervisor
{
    public function run(ProcessRequest $request): ProcessResult
    {
        $workingDirectory = realpath($request->workingDirectory);
        if (!is_string($workingDirectory) || !is_dir($workingDirectory)) {
            throw new RuntimeException('Process working directory cannot be resolved: ' . $request->workingDirectory);
        }

        $argv = $this->processGroupArgv($request->argv);
        $startedAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $started = hrtime(true);
        $process = @proc_open(
            $argv,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            $request->environment,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start process: ' . $request->argv[0]);
        }

        fwrite($pipes[0], $request->stdin);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $timedOut = false;
        $status = proc_get_status($process);
        $pid = is_int($status['pid'] ?? null) ? $status['pid'] : null;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                $candidateExit = $status['exitcode'] ?? -1;
                $exitCode = is_int($candidateExit) ? $candidateExit : -1;
                break;
            }

            $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;
            if ($elapsedSeconds >= $request->timeoutSeconds) {
                $timedOut = true;
                $this->terminate($process, $pid);
                usleep(100_000);
                $status = proc_get_status($process);
                if ($status['running'] ?? false) {
                    $this->kill($process, $pid);
                }
                break;
            }

            usleep(10_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExit = proc_close($process);
        if (!$timedOut && $exitCode < 0 && $closedExit >= 0) {
            $exitCode = $closedExit;
        }
        if ($timedOut && $exitCode === 0) {
            $exitCode = 124;
        }
        if ($timedOut && $exitCode < 0) {
            $exitCode = 124;
        }

        return new ProcessResult(
            $exitCode,
            $stdout,
            $stderr,
            $timedOut,
            $startedAt,
            (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }

    /** @param non-empty-list<non-empty-string> $argv @return non-empty-list<non-empty-string> */
    private function processGroupArgv(array $argv): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            foreach (['/usr/bin/setsid', '/bin/setsid'] as $setsid) {
                if (is_executable($setsid)) {
                    return [$setsid, '--wait', ...$argv];
                }
            }
        }

        return $argv;
    }

    /** @param resource $process */
    private function terminate($process, ?int $pid): void
    {
        if ($pid !== null && PHP_OS_FAMILY !== 'Windows' && function_exists('posix_kill')) {
            @posix_kill(-$pid, defined('SIGTERM') ? SIGTERM : 15);
        }
        @proc_terminate($process, 15);
    }

    /** @param resource $process */
    private function kill($process, ?int $pid): void
    {
        if ($pid !== null && PHP_OS_FAMILY !== 'Windows' && function_exists('posix_kill')) {
            @posix_kill(-$pid, defined('SIGKILL') ? SIGKILL : 9);
        }
        @proc_terminate($process, 9);
    }
}
