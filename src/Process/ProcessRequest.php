<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

use InvalidArgumentException;

final readonly class ProcessRequest
{
    /** @var non-empty-list<non-empty-string> */
    public array $argv;

    /** @var array<string, string> */
    public array $environment;

    /**
     * @param list<string> $argv
     * @param array<string, string> $environment
     */
    public function __construct(
        array $argv,
        public string $workingDirectory,
        public string $stdin,
        array $environment,
        public int $timeoutSeconds,
    ) {
        if ($argv === []) {
            throw new InvalidArgumentException('Process request requires at least one argv entry.');
        }

        foreach ($argv as $argument) {
            if ($argument === '') {
                throw new InvalidArgumentException('Process argv entries must be non-empty strings.');
            }
        }

        if ($this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('Process request requires a positive timeout.');
        }

        /** @var non-empty-list<non-empty-string> $argv */
        $this->argv = $argv;
        $this->environment = $environment;
    }
}
