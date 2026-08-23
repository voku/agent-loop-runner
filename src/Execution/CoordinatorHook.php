<?php

declare(strict_types=1);
namespace voku\AgentLoopRunner\Execution;
interface CoordinatorHook { public function reached(string $boundary): void; }
