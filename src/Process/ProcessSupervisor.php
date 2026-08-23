<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

interface ProcessSupervisor
{
    public function run(ProcessRequest $request): ProcessResult;
}
