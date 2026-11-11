<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Execution;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionProjection;

/**
 * An exact owner snapshot from inside Runner's locked execution boundary.
 *
 * The typed snapshot survives the transition from an interrupted run/resume
 * into an optional foreground watcher; a string error is not sufficient proof.
 */
final class WaitingForAttention extends RuntimeException
{
    public function __construct(public readonly ExecutionProjection $projection)
    {
        if ($projection->attention === null) {
            throw new InvalidArgumentException('A WAITING exception requires an owner-projected pending Attention.');
        }

        parent::__construct('WAITING_FOR_ATTENTION: ' . $projection->attention->id);
    }
}
