<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Execution;

use voku\AgentLoop\Execution\ExecutionEnvironmentObservation;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\StageArtifactObservation;
use voku\AgentLoop\Execution\StageCandidateObservation;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageResult;

final readonly class AgentLoopExecutionGateway implements ExecutionGatewayPort
{
    public function __construct(private ExecutionGateway $gateway)
    {
    }

    public function projection(string $taskId): ExecutionProjection
    {
        return $this->gateway->projection($taskId);
    }

    public function prepareStage(string $taskId, string $stageId): StageExecutionBundle
    {
        return $this->gateway->prepareStage($taskId, $stageId);
    }

    public function prepareStageForEnvironment(
        string $taskId,
        string $stageId,
        ExecutionEnvironmentObservation $observation,
    ): StageExecutionBundle {
        return $this->gateway->prepareStageForEnvironment($taskId, $stageId, $observation);
    }

    public function recordStageCandidate(StageCandidateObservation $observation): string
    {
        return $this->gateway->recordStageCandidate($observation);
    }

    public function recordStageArtifact(StageArtifactObservation $observation): string
    {
        return $this->gateway->recordStageArtifact($observation);
    }

    public function submitStageResult(StageResult $result): ExecutionProjection
    {
        return $this->gateway->submitStageResult($result);
    }

    public function runDeterministicStage(string $taskId, string $stageId): ExecutionProjection
    {
        return $this->gateway->runDeterministicStage($taskId, $stageId);
    }
}
