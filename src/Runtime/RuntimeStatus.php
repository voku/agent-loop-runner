<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

enum RuntimeStatus: string
{
    case PREPARED = 'prepared';
    case PROCESS_STARTED = 'process_started';
    case PROCESS_EXITED = 'process_exited';
    case RESULT_PERSISTED = 'result_persisted';
    case SUBMISSION_ATTEMPTED = 'submission_attempted';
    case RECONCILED_ACCEPTED = 'reconciled_accepted';
    case WAITING_FOR_ATTENTION = 'waiting_for_attention';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
