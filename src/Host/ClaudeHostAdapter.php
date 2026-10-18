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
        // isolated workspace would otherwise come back blocked on its first edit or validation run. acceptEdits
        // covers file edits only; validation is granted as exact Bash(<command>) rules derived from the approved
        // Contract (no wildcard, no bypass flag, nothing persisted). Read-only stages keep the default mode.
        if ($request->mayMutate) {
            $rules = ClaudeExactBashRules::fromValidation($request->validationCommands);

            return [
                $binaryPath,
                '--permission-mode', 'acceptEdits',
                ...($rules === [] ? [] : ['--allowedTools', ...$rules]),
                '-p', $request->prompt,
            ];
        }

        return [$binaryPath, '-p', $request->prompt];
    }
}
