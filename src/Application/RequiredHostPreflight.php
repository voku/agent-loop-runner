<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

use RuntimeException;
use voku\AgentLoop\Execution\CurrentExecutionStageProjection;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Process\ProcessSupervisor;

final readonly class RequiredHostPreflight
{
    /**
     * @param array<string, HostAdapter> $hosts
     * @param array<string, string> $environment
     */
    public function __construct(
        private RunnerConfig $config,
        private array $hosts,
        private ProcessSupervisor $supervisor,
        private array $environment,
    ) {
    }

    public function observe(
        ExecutionProjection $authority,
        CurrentExecutionStageProjection $stage,
        string $workingDirectory,
    ): ?RequiredHostObservation {
        $this->assertSameIdentity($authority, $stage);

        if ($authority->attention !== null || $authority->complete() || $stage->stageKind === ExecutionStageKind::DETERMINISTIC) {
            return null;
        }
        if ($stage->stageKind !== ExecutionStageKind::AGENT || $stage->roleId === null || trim($stage->roleId) === '') {
            throw new RuntimeException('STALE_RUN: current agent stage has no exact projected role.');
        }

        $hostId = $this->config->hostForRole($stage->roleId);
        $host = $this->hosts[$hostId] ?? null;
        if (!$host instanceof HostAdapter) {
            return new RequiredHostObservation($stage->roleId, $hostId, false, null);
        }

        $availability = $host->probe($this->supervisor, $workingDirectory, $this->environment);
        if ($availability->isNearLimit($this->config->quotaUsageThreshold)) {
            $fallbackHostId = $this->config->fallbackHostForRole($stage->roleId);
            $fallbackHost = $fallbackHostId !== null ? ($this->hosts[$fallbackHostId] ?? null) : null;
            if ($fallbackHost instanceof HostAdapter && $fallbackHostId !== $hostId) {
                $fallbackAvailability = $fallbackHost->probe($this->supervisor, $workingDirectory, $this->environment);
                if ($fallbackAvailability->available() && !$fallbackAvailability->isNearLimit($this->config->quotaUsageThreshold)) {
                    return new RequiredHostObservation(
                        $stage->roleId,
                        $fallbackHostId,
                        true,
                        $fallbackAvailability->version,
                    );
                }
            }

            return new RequiredHostObservation(
                $stage->roleId,
                $hostId,
                false,
                $availability->version,
            );
        }

        return new RequiredHostObservation(
            $stage->roleId,
            $hostId,
            $availability->available(),
            $availability->version,
        );
    }

    private function assertSameIdentity(
        ExecutionProjection $authority,
        CurrentExecutionStageProjection $stage,
    ): void {
        if ($authority->taskId !== $stage->taskId
            || $authority->runId !== $stage->runId
            || $authority->contractRevision !== $stage->contractRevision
            || !hash_equals($authority->executionPlanDigest, $stage->executionPlanDigest)
            || $authority->currentStageId !== $stage->stageId
            || $authority->currentAttempt !== $stage->attempt
            || !hash_equals($authority->candidateRevision, $stage->candidateRevision)) {
            throw new RuntimeException('STALE_RUN: current execution stage projection conflicts with authoritative execution identity.');
        }
    }
}
