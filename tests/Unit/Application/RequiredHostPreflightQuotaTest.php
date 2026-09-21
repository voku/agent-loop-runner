<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Execution\CurrentExecutionStageProjection;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoopRunner\Application\RequiredHostPreflight;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\HostAvailability;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Host\HostExecutionResult;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\ProcessSupervisor;

final class RequiredHostPreflightQuotaTest extends TestCase
{
    public function testPreflightMarksHostUnavailableWhenQuotaNearLimitAndNoFallback(): void
    {
        $config = new RunnerConfig(
            ['codex' => ['binary' => 'codex']],
            ['builder' => 'codex'],
            1800,
            ['PATH'],
        );

        $exhaustedHost = new PreflightQuotaFakeHost('codex', true, remainingRatio: 0.03); // 97% used
        $preflight = new RequiredHostPreflight(
            $config,
            ['codex' => $exhaustedHost],
            new ForegroundProcessSupervisor(),
            ['PATH' => '/bin'],
        );

        $observation = $preflight->observe(
            $this->authority(),
            $this->stage(roleId: 'builder'),
            sys_get_temp_dir(),
        );

        self::assertNotNull($observation);
        self::assertSame('builder', $observation->roleId);
        self::assertSame('codex', $observation->hostId);
        self::assertFalse($observation->available, 'Exhausted host with no fallback must be observed as unavailable');
    }

    public function testPreflightSwitchesToFallbackHostWhenQuotaNearLimit(): void
    {
        $config = new RunnerConfig(
            [
                'codex' => ['binary' => 'codex'],
                'claude' => ['binary' => 'claude'],
            ],
            ['builder' => 'codex'],
            1800,
            ['PATH'],
            [],
            ['builder' => 'claude'],
        );

        $exhaustedHost = new PreflightQuotaFakeHost('codex', true, remainingRatio: 0.02); // 98% used
        $healthyFallback = new PreflightQuotaFakeHost('claude', true, remainingRatio: 0.80); // 20% used
        $preflight = new RequiredHostPreflight(
            $config,
            [
                'codex' => $exhaustedHost,
                'claude' => $healthyFallback,
            ],
            new ForegroundProcessSupervisor(),
            ['PATH' => '/bin'],
        );

        $observation = $preflight->observe(
            $this->authority(),
            $this->stage(roleId: 'builder'),
            sys_get_temp_dir(),
        );

        self::assertNotNull($observation);
        self::assertSame('builder', $observation->roleId);
        self::assertSame('claude', $observation->hostId, 'Preflight observation should route to healthy fallback host');
        self::assertTrue($observation->available);
    }

    private function authority(): ExecutionProjection
    {
        return new ExecutionProjection(
            'TASK-1',
            'run:TASK-1',
            1,
            ExecutionProfileName::SURGICAL,
            'sha256:' . str_repeat('a', 64),
            'builder',
            1,
            null,
            [],
            'sha256:' . str_repeat('b', 64),
        );
    }

    private function stage(string $roleId = 'builder'): CurrentExecutionStageProjection
    {
        return new CurrentExecutionStageProjection(
            'TASK-1',
            'run:TASK-1',
            1,
            'sha256:' . str_repeat('a', 64),
            'builder',
            1,
            'sha256:' . str_repeat('b', 64),
            ExecutionStageKind::AGENT,
            $roleId,
        );
    }
}

final class PreflightQuotaFakeHost implements HostAdapter
{
    public function __construct(
        private string $id,
        private bool $available,
        private ?float $remainingRatio = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability
    {
        return new HostAvailability(
            $this->id,
            $this->available ? '/bin/' . $this->id : null,
            $this->available ? '1.0.0' : null,
            $this->available ? null : 'binary not found',
            $this->remainingRatio,
        );
    }

    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        throw new \BadMethodCallException('execute should not be called in preflight');
    }
}
