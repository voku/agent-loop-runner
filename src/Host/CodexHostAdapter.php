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
        $argv = [$binaryPath, 'exec', '--ephemeral'];
        if ($request->model !== null) {
            $argv[] = '--model';
            $argv[] = $request->model;
        }
        if ($request->reasoningEffort !== null) {
            $argv[] = '--config';
            $argv[] = 'model_reasoning_effort=' . $request->reasoningEffort;
        }
        $argv[] = '-';

        return $argv;
    }

    protected function stdin(HostExecutionRequest $request): string
    {
        return $request->prompt;
    }
}
