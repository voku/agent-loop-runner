<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class OpenCodeHostAdapter extends AbstractCliHostAdapter
{
    public function id(): string
    {
        return 'opencode';
    }

    protected function argv(string $binaryPath, HostExecutionRequest $request): array
    {
        return [$binaryPath, 'run', $request->prompt];
    }
}
