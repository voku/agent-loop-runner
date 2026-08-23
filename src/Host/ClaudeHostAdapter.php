<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class ClaudeHostAdapter extends AbstractCliHostAdapter
{
    public function id(): string
    {
        return 'claude';
    }

    protected function argv(string $binaryPath, HostExecutionRequest $request): array
    {
        return [$binaryPath, '-p', $request->prompt];
    }
}
