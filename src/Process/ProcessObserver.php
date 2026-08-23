<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Process;
interface ProcessObserver { /** @param non-empty-string $startedAt */ public function started(int $pid,string $startedAt):void; }
