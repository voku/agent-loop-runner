<?php

declare(strict_types=1);
namespace voku\AgentLoopRunner\Execution;
use voku\AgentLoop\Execution\ExecutionEnvironmentObservation;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageResult;
interface ExecutionGatewayPort
{
    public function projection(string $taskId): ExecutionProjection;
    public function prepareStage(string $taskId, string $stageId): StageExecutionBundle;
    public function prepareStageForEnvironment(string $taskId, string $stageId, ExecutionEnvironmentObservation $observation): StageExecutionBundle;
    public function submitStageResult(StageResult $result): ExecutionProjection;
    public function runDeterministicStage(string $taskId, string $stageId): ExecutionProjection;
}
