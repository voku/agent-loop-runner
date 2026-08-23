<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

use voku\AgentLoopRunner\Process\ProcessSupervisor;

interface HostAdapter
{
    public function id(): string;

    /** @param array<string, string> $environment */
    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability;

    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult;
}
