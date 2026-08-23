<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

use RuntimeException;
use voku\AgentLoopRunner\Config\RunnerConfig;

final readonly class HostAdapterFactory
{
    public function create(string $hostId, RunnerConfig $config): HostAdapter
    {
        $binary = $config->binary($hostId);

        return match ($hostId) {
            'codex' => new CodexHostAdapter($binary),
            'claude' => new ClaudeHostAdapter($binary),
            'opencode' => new OpenCodeHostAdapter($binary),
            default => throw new RuntimeException('HOST_UNAVAILABLE: unsupported configured host ' . $hostId . '.'),
        };
    }
}
