<?php

declare(strict_types=1);
namespace voku\AgentLoopRunner\Execution;
final readonly class NullCoordinatorHook implements CoordinatorHook { public function reached(string $boundary): void {} }
