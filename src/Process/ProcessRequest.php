<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

use InvalidArgumentException;

final readonly class ProcessRequest
{
    /**
     * @param non-empty-list<non-empty-string> $argv
     * @param array<string, string> $environment
     */
    public function __construct(
        public array $argv,
        public string $workingDirectory,
        public string $stdin,
        public array $environment,
        public int $timeoutSeconds,
    ) {
        if ($this->argv === [] || $this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('Process request requires argv and a positive timeout.');
        }
        foreach ($this->argv as $argument) {
            if ($argument === '') {
                throw new InvalidArgumentException('Process argv entries must be non-empty strings.');
            }
        }
    }
}
