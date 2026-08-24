<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

enum AttemptStatus: string
{
    case Prepared = 'prepared';
    case ProcessStarted = 'process_started';
    case ProcessExited = 'process_exited';
    case CandidateObserved = 'candidate_observed';
    case ResultPersisted = 'result_persisted';
    case SubmissionAttempted = 'submission_attempted';
    case ReconciledAccepted = 'reconciled_accepted';
    case WaitingForAttention = 'waiting_for_attention';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
