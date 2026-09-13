<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\AttentionKind;
use voku\AgentLoop\Execution\AttentionRequest;
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

final class RequiredHostPreflightTest extends TestCase
{
    public function testRequiredHostAvailable(): void
    {
        $preflight = new RequiredHostPreflight(
            $this->config(),
            [
                'codex' => new FakeHostAdapter('codex', true, '2.5.0'),
                'claude' => new FakeHostAdapter('claude', false),
            ],
            new ForegroundProcessSupervisor(),
            ['PATH' => '/bin'],
        );

        $observation = $preflight->observe(
            $this->authority(),
            $this->stage(roleId: 'builder'),
            '/tmp',
        );

        self::assertNotNull($observation);
        self::assertSame('builder', $observation->roleId);
        self::assertSame('codex', $observation->hostId);
        self::assertTrue($observation->available);
        self::assertSame('2.5.0', $observation->version);
        self::assertSame([
            'role_id' => 'builder',
            'host_id' => 'codex',
            'available' => true,
            'version' => '2.5.0',
        ], $observation->toArray());
    }

    public function testRequiredHostUnavailable(): void
    {
        $preflight = new RequiredHostPreflight(
            $this->config(),
            [
                'codex' => new FakeHostAdapter('codex', false, null, 'binary not found'),
                'claude' => new FakeHostAdapter('claude', false),
            ],
            new ForegroundProcessSupervisor(),
            ['PATH' => '/bin'],
        );

        $observation = $preflight->observe(
            $this->authority(),
            $this->stage(roleId: 'builder'),
            '/tmp',
        );

        self::assertNotNull($observation);
        self::assertSame('builder', $observation->roleId);
        self::assertSame('codex', $observation->hostId);
        self::assertFalse($observation->available);
        self::assertNull($observation->version);
    }

    public function testDifferentHostAvailableExplicitlyDoesNotCount(): void
    {
        $preflight = new RequiredHostPreflight(
            $this->config(),
            [
                // Codex is available, but the role 'reviewer' requires Claude!
                'codex' => new FakeHostAdapter('codex', true, '2.5.0'),
                'claude' => new FakeHostAdapter('claude', false, null, 'claude binary missing'),
            ],
            new ForegroundProcessSupervisor(),
            ['PATH' => '/bin'],
        );

        $observation = $preflight->observe(
            $this->authority(),
            $this->stage(roleId: 'reviewer'),
            '/tmp',
        );

        self::assertNotNull($observation);
        self::assertSame('reviewer', $observation->roleId);
        self::assertSame('claude', $observation->hostId);
        self::assertFalse($observation->available);
        self::assertNull($observation->version);
    }

    public function testIdentityMismatchFailsClosed(): void
    {
        $preflight = new RequiredHostPreflight(
            $this->config(),
            ['codex' => new FakeHostAdapter('codex', true)],
            new ForegroundProcessSupervisor(),
            ['PATH' => '/bin'],
        );

        $mismatches = [
            'taskId' => [$this->authority(taskId: 'TASK-A'), $this->stage(taskId: 'TASK-B')],
            'runId' => [$this->authority(runId: 'run:1'), $this->stage(runId: 'run:2')],
            'contractRevision' => [$this->authority(contractRevision: 1), $this->stage(contractRevision: 2)],
            'planDigest' => [$this->authority(planDigest: 'sha256:aaa'), $this->stage(planDigest: 'sha256:bbb')],
            'stageId' => [$this->authority(stageId: 'stage-1'), $this->stage(stageId: 'stage-2')],
            'attempt' => [$this->authority(attempt: 1), $this->stage(attempt: 2)],
            'candidateRevision' => [$this->authority(candidateRevision: 'cand-1'), $this->stage(candidateRevision: 'cand-2')],
        ];

        foreach ($mismatches as $label => [$authority, $stage]) {
            try {
                $preflight->observe($authority, $stage, '/tmp');
                self::fail('Expected identity mismatch failure for ' . $label);
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'STALE_RUN: current execution stage projection conflicts with authoritative execution identity.',
                    $exception->getMessage(),
                    'Failed assertion on ' . $label,
                );
            }
        }
    }

    public function testDeterministicOrCompletedOrAttentionStageRequiresNoHost(): void
    {
        $preflight = new RequiredHostPreflight(
            $this->config(),
            ['codex' => new FakeHostAdapter('codex', true)],
            new ForegroundProcessSupervisor(),
            ['PATH' => '/bin'],
        );

        // 1. Deterministic stage
        $deterministicStage = $this->stage(
            stageKind: ExecutionStageKind::DETERMINISTIC,
            roleId: null,
        );
        self::assertNull($preflight->observe($this->authority(), $deterministicStage, '/tmp'));

        // 2. Completed authority
        $completedAuthority = $this->authority(complete: true);
        $stage = $this->stage(stageId: null, roleId: null);
        self::assertNull($preflight->observe($completedAuthority, $stage, '/tmp'));

        // 3. Attention required
        $attention = new AttentionRequest(
            'att-1',
            'TASK-1',
            'run:TASK-1',
            AttentionKind::APPROVAL_REQUIRED,
            'Decision needed',
            'implementation',
            '2026-09-13T12:00:00Z',
        );
        $attentionAuthority = $this->authority(attention: $attention);
        self::assertNull($preflight->observe($attentionAuthority, $this->stage(), '/tmp'));
    }

    public function testAgentStageWithoutRoleIdThrowsException(): void
    {
        $preflight = new RequiredHostPreflight(
            $this->config(),
            ['codex' => new FakeHostAdapter('codex', true)],
            new ForegroundProcessSupervisor(),
            ['PATH' => '/bin'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_RUN: current agent stage has no exact projected role.');

        $preflight->observe(
            $this->authority(),
            $this->stage(roleId: null),
            '/tmp',
        );
    }

    private function config(): RunnerConfig
    {
        return new RunnerConfig(
            [
                'codex' => ['binary' => 'codex'],
                'claude' => ['binary' => 'claude'],
                'opencode' => ['binary' => 'opencode'],
                'agy' => ['binary' => 'agy'],
            ],
            [
                'builder' => 'codex',
                'reviewer' => 'claude',
            ],
            1800,
            ['PATH'],
        );
    }

    private function authority(
        string $taskId = 'TASK-1',
        string $runId = 'run:TASK-1',
        int $contractRevision = 2,
        string $planDigest = 'sha256:plan',
        ?string $stageId = 'implementation',
        int $attempt = 1,
        string $candidateRevision = 'sha256:cand',
        ?AttentionRequest $attention = null,
        bool $complete = false,
    ): ExecutionProjection {
        return new ExecutionProjection(
            $taskId,
            $runId,
            $contractRevision,
            ExecutionProfileName::SURGICAL,
            $planDigest,
            $complete ? null : $stageId,
            $attempt,
            $attention,
            [],
            $candidateRevision,
        );
    }

    private function stage(
        string $taskId = 'TASK-1',
        string $runId = 'run:TASK-1',
        int $contractRevision = 2,
        string $planDigest = 'sha256:plan',
        ?string $stageId = 'implementation',
        int $attempt = 1,
        string $candidateRevision = 'sha256:cand',
        ExecutionStageKind $stageKind = ExecutionStageKind::AGENT,
        ?string $roleId = 'builder',
    ): CurrentExecutionStageProjection {
        return new CurrentExecutionStageProjection(
            $taskId,
            $runId,
            $contractRevision,
            $planDigest,
            $stageId,
            $attempt,
            $candidateRevision,
            $stageKind,
            $roleId,
        );
    }
}

final class FakeHostAdapter implements HostAdapter
{
    public function __construct(
        private string $id,
        private bool $available,
        private ?string $version = '1.0.0',
        private ?string $reason = null,
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
            $this->available ? $this->version : null,
            $this->available ? null : ($this->reason ?? 'binary not found'),
        );
    }

    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        throw new \BadMethodCallException('execute should not be called in preflight');
    }
}
