<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class CodexHostAdapter extends AbstractCliHostAdapter
{
    public function id(): string
    {
        return 'codex';
    }

    /** @param non-empty-string $binaryPath */
    protected function argv(string $binaryPath, HostExecutionRequest $request): array
    {
        return [$binaryPath, 'exec', '--ephemeral', '-'];
    }

    protected function stdin(HostExecutionRequest $request): string
    {
        return $request->prompt;
    }
}
