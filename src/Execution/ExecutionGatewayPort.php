<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Execution;

use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\StageArtifactObservation;
use voku\AgentLoop\Execution\StageCandidateObservation;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageResult;

interface ExecutionGatewayPort
{
    public function projection(string $taskId): ExecutionProjection;

    public function prepareStage(string $taskId, string $stageId): StageExecutionBundle;

    /** @return non-empty-string */
    public function recordStageCandidate(StageCandidateObservation $observation): string;

    /** @return non-empty-string */
    public function recordStageArtifact(StageArtifactObservation $observation): string;

    public function submitStageResult(StageResult $result): ExecutionProjection;

    public function runDeterministicStage(string $taskId, string $stageId): ExecutionProjection;
}
