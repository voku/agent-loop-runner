<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Execution;

use RuntimeException;

final class InvalidStageResult extends RuntimeException
{
    public const string FAILURE_CODE = 'INVALID_STAGE_RESULT';
}
