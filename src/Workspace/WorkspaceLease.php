<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Workspace;

use InvalidArgumentException;

final readonly class WorkspaceLease
{
    public function __construct(
        public string $taskId,
        public string $runId,
        public string $path,
        public string $baseCommit,
        public string $ownerStageId,
        public int $attempt,
        public bool $mayMutate,
    ) {
        if (trim($this->taskId) === '' || trim($this->runId) === '' || trim($this->ownerStageId) === '') {
            throw new InvalidArgumentException('Workspace lease requires task, Run and stage identifiers.');
        }
        if ($this->attempt < 1) {
            throw new InvalidArgumentException('Workspace lease attempt must be positive.');
        }
        if (preg_match('/^[0-9a-f]{40,64}$/', $this->baseCommit) !== 1) {
            throw new InvalidArgumentException('Workspace lease requires an exact Git base commit.');
        }
    }

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'path' => $this->path,
            'base_commit' => $this->baseCommit,
            'owner_stage_id' => $this->ownerStageId,
            'attempt' => $this->attempt,
            'may_mutate' => $this->mayMutate,
        ];
    }
}
