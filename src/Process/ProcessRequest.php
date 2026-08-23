<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

use InvalidArgumentException;

final readonly class ProcessRequest
{
    /** @var non-empty-list<non-empty-string> */
    public array $argv;
    /** @var non-empty-string */
    public string $workingDirectory;
    /** @var array<string, string> */
    public array $environment;
    public string $stdin;
    public int $timeoutSeconds;
    public ProcessObserver $observer;

    /**
     * @param list<string> $argv
     * @param array<string, string> $environment
     */
    public function __construct(array $argv, string $workingDirectory, string $stdin, array $environment, int $timeoutSeconds, ProcessObserver $observer = new NullProcessObserver())
    {
        if ($argv === [] || $timeoutSeconds < 1 || $workingDirectory === '') {
            throw new InvalidArgumentException('Process request requires argv, working directory, and a positive timeout.');
        }
        foreach ($argv as $argument) {
            if ($argument === '') {
                throw new InvalidArgumentException('Process argv entries must be non-empty strings.');
            }
        }
        $this->argv = $argv;
        $this->workingDirectory = $workingDirectory;
        $this->stdin = $stdin;
        $this->environment = $environment;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->observer = $observer;
    }
}
