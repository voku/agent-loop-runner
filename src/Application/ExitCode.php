<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Application;
final class ExitCode { public const int OK=0; public const int USAGE=2; public const int HOST_UNAVAILABLE=10; public const int PROCESS_FAILED=11; public const int PROCESS_TIMEOUT=12; public const int INVALID_STAGE_RESULT=13; public const int STALE_STATE=14; public const int TRANSITION_REJECTED=15; public const int WAITING_FOR_ATTENTION=16; public const int INTERNAL=70; }
