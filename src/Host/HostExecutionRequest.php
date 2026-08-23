<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class HostExecutionRequest
{
    /** @param array<string, string> $environment */
    public function __construct(
        public string $roleId,
        public string $workingDirectory,
        public string $prompt,
        public array $environment,
        public int $timeoutSeconds,
    ) {
    }
}
