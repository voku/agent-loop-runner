<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Git;

final readonly class GitCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
