<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use RuntimeException;
use voku\AgentLoopRunner\Process\ProcessLifecycleObserver;

final readonly class RuntimeProcessObserver implements ProcessLifecycleObserver
{
    public function __construct(
        private RuntimeJournalStore $journals,
        private string $taskId,
        private string $submissionId,
        private string $hostId,
        private ?string $hostVersion,
    ) {
    }

    public function started(int $pid, string $startedAt): void
    {
        $journal = $this->journals->load($this->taskId);
        if ($journal === null || $journal->submissionId !== $this->submissionId) {
            throw new RuntimeException('STALE_RUN: runtime journal changed before owned process start could be recorded.');
        }
        if ($journal->status !== RuntimeStatus::PROCESS_STARTED || $journal->processPid !== null) {
            throw new RuntimeException('STALE_RUN: owned PID can be attached only to the armed Runner process attempt.');
        }

        $this->journals->save($journal->withProcessStarted($pid, $startedAt, $this->hostId, $this->hostVersion));
    }
}
