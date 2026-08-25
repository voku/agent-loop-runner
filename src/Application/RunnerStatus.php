<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;

final readonly class RunnerStatus
{
    public const string RUN = 'run';
    public const string RESUME = 'resume';
    public const string CANCEL = 'cancel';

    public function __construct(
        public ExecutionProjection $authority,
        public ?RuntimeAttempt $observation,
    ) {
    }

    public function allows(string $control): bool
    {
        return match ($control) {
            self::RUN, self::RESUME => $this->executionAvailable(),
            self::CANCEL => $this->cancellationAvailable(),
            default => false,
        };
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
     *     controls: array{run: bool, resume: bool, cancel: bool},
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
            'controls' => [
                self::RUN => $this->allows(self::RUN),
                self::RESUME => $this->allows(self::RESUME),
                self::CANCEL => $this->allows(self::CANCEL),
            ],
            'runner_observation' => $this->observation?->toArray(),
        ];
    }

    private function executionAvailable(): bool
    {
        $stageId = $this->authority->currentStageId;
        if ($this->authority->complete() || $this->authority->attention !== null || $stageId === null) {
            return false;
        }
        if ($this->observation === null) {
            return true;
        }
        if (!$this->observation->sameAuthority(
            $this->authority->runId,
            $this->authority->contractRevision,
            $this->authority->executionPlanDigest,
            $stageId,
            $this->authority->currentAttempt,
        )) {
            return true;
        }
        if ($this->observation->stageResult !== null) {
            return true;
        }

        return $this->observation->status === AttemptStatus::Prepared;
    }

    private function cancellationAvailable(): bool
    {
        if ($this->observation?->status !== AttemptStatus::ProcessStarted) {
            return false;
        }

        return isset($this->observation->process['pid'], $this->observation->process['process_fingerprint']);
    }
}
