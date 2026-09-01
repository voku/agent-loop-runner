<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class AgyHostAdapter extends AbstractCliHostAdapter
{
    public function id(): string
    {
        return 'agy';
    }

    /** @param non-empty-string $binaryPath */
    protected function argv(string $binaryPath, HostExecutionRequest $request): array
    {
        return [$binaryPath, '--dangerously-skip-permissions', '-p', $request->prompt];
    }
}
