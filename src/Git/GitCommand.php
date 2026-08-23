<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Git;

use RuntimeException;
use voku\AgentLoopRunner\Process\ProcessRequest;
use voku\AgentLoopRunner\Process\ProcessSupervisor;

final readonly class GitCommand
{
    /** @param array<string, string> $environment */
    public function __construct(
        private ProcessSupervisor $processSupervisor,
        private array $environment,
    ) {
    }

    /** @param list<string> $arguments */
    public function run(string $workingDirectory, array $arguments, int $timeoutSeconds = 30): GitCommandResult
    {
        $result = $this->processSupervisor->run(new ProcessRequest(
            ['git', ...$arguments],
            $workingDirectory,
            '',
            $this->environment,
            $timeoutSeconds,
        ));

        return new GitCommandResult($result->exitCode, $result->stdout, $result->stderr);
    }

    /** @param list<string> $arguments */
    public function requireSuccess(string $workingDirectory, array $arguments, int $timeoutSeconds = 30): GitCommandResult
    {
        $result = $this->run($workingDirectory, $arguments, $timeoutSeconds);
        if (!$result->successful()) {
            $stderr = trim($result->stderr);
            throw new RuntimeException(sprintf(
                'Git command failed in %s: git %s (exit %d)%s',
                $workingDirectory,
                implode(' ', $arguments),
                $result->exitCode,
                $stderr !== '' ? ': ' . $stderr : '',
            ));
        }

        return $result;
    }
}
