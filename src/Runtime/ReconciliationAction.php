<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

enum ReconciliationAction: string
{
    case RESUBMIT_PERSISTED_RESULT = 'resubmit_persisted_result';
    case CONTINUE_AUTHORIZED_ATTEMPT = 'continue_authorized_attempt';
    case WAITING_FOR_ATTENTION = 'waiting_for_attention';
    case COMPLETE = 'complete';
}
