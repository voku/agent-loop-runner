<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut,
        public string $startedAt,
        public string $finishedAt,
    ) {
    }

    public function successful(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }
}
