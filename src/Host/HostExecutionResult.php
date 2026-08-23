<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

use voku\AgentLoopRunner\Process\ProcessResult;

final readonly class HostExecutionResult
{
    public function __construct(
        public string $hostId,
        public ProcessResult $process,
    ) {
    }
}
