<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Process;
final readonly class NullProcessObserver implements ProcessObserver{/** @param non-empty-string $startedAt */ public function started(int$pid,string$startedAt):void{}}
