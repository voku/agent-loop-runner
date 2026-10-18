<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class ClaudeHostAdapter extends AbstractCliHostAdapter
{
    public function id(): string
    {
        return 'claude';
    }

    /** @param non-empty-string $binaryPath */
    protected function argv(string $binaryPath, HostExecutionRequest $request): array
    {
        // A headless session cannot answer permission prompts, so a stage that Loop authorizes to mutate its
        // isolated workspace would otherwise come back blocked on its first edit. acceptEdits covers file edits
        // only; read-only stages keep the default mode, and no bypass flag is ever used.
        if ($request->mayMutate) {
            return [$binaryPath, '--permission-mode', 'acceptEdits', '-p', $request->prompt];
        }

        return [$binaryPath, '-p', $request->prompt];
    }
}
