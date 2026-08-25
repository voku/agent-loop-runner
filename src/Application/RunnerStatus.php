<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;

final readonly class RunnerStatus
{
    public function __construct(
        public ExecutionProjection $authority,
        public ?RuntimeAttempt $observation,
    ) {
    }

    /**
     * @return array{
     *     authority: array{
     *         task_id: string,
     *         run_id: string,
     *         contract_revision: int,
     *         profile: string,
     *         execution_plan_digest: string,
     *         current_stage_id: string|null,
     *         current_attempt: int,
     *         attention_id: string|null,
     *         complete: bool,
     *         candidate_revision: string
     *     },
     *     runner_observation: array<string, mixed>|null
     * }
     */
    public function toArray(): array
    {
        return [
            'authority' => [
                'task_id' => $this->authority->taskId,
                'run_id' => $this->authority->runId,
                'contract_revision' => $this->authority->contractRevision,
                'profile' => $this->authority->profile->value,
                'execution_plan_digest' => $this->authority->executionPlanDigest,
                'current_stage_id' => $this->authority->currentStageId,
                'current_attempt' => $this->authority->currentAttempt,
                'attention_id' => $this->authority->attention?->id,
                'complete' => $this->authority->complete(),
                'candidate_revision' => $this->authority->candidateRevision,
            ],
            'runner_observation' => $this->observation?->toArray(),
        ];
    }
}
