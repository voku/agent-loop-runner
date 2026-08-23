<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

interface ProcessLifecycleObserver
{
    public function started(int $pid, string $startedAt): void;
}
