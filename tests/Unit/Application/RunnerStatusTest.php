<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoopRunner\Application\RunnerStatus;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;

final class RunnerStatusTest extends TestCase
{
    public function testKeepsAuthorityAndRunnerObservationSeparate(): void
    {
        $authority = $this->authority();
        $observation = $this->observation(AttemptStatus::Prepared);

        $status = new RunnerStatus($authority, $observation);
        $serialized = $status->toArray();

        self::assertSame($authority, $status->authority);
        self::assertSame($observation, $status->observation);
        self::assertSame('implementation', $serialized['authority']['current_stage_id']);
        self::assertSame('codex', $serialized['runner_observation']['host_id'] ?? null);
        self::assertArrayNotHasKey('host_id', $serialized['authority']);
        self::assertArrayNotHasKey('complete', $serialized['runner_observation'] ?? []);
        self::assertTrue($serialized['controls']['run']);
        self::assertTrue($serialized['controls']['resume']);
        self::assertFalse($serialized['controls']['cancel']);
    }

    public function testActiveProcessProjectsCancelInsteadOfAnotherExecution(): void
    {
        $status = new RunnerStatus(
            $this->authority(),
            $this->observation(AttemptStatus::ProcessStarted, [
                'pid' => 12345,
                'process_fingerprint' => 'linux:12345:fixture',
            ]),
        );

        self::assertFalse($status->allows(RunnerStatus::RUN));
        self::assertFalse($status->allows(RunnerStatus::RESUME));
        self::assertTrue($status->allows(RunnerStatus::CANCEL));
    }

    public function testCompleteAuthorityProjectsNoExecutionControls(): void
    {
        $authority = new ExecutionProjection(
            'TASK-1',
            'run:TASK-1',
            2,
            ExecutionProfileName::SURGICAL,
            'sha256:plan',
            null,
            3,
            null,
            [],
            'abc123',
        );
        $status = new RunnerStatus($authority, null);

        self::assertFalse($status->allows(RunnerStatus::RUN));
        self::assertFalse($status->allows(RunnerStatus::RESUME));
        self::assertFalse($status->allows(RunnerStatus::CANCEL));
    }

    private function authority(): ExecutionProjection
    {
        return new ExecutionProjection(
            'TASK-1',
            'run:TASK-1',
            2,
            ExecutionProfileName::SURGICAL,
            'sha256:plan',
            'implementation',
            3,
            null,
            [],
            'abc123',
        );
    }

    /**
     * @param array{pid?: int, process_fingerprint?: non-empty-string} $process
     */
    private function observation(AttemptStatus $status, array $process = []): RuntimeAttempt
    {
        return new RuntimeAttempt(
            'TASK-1',
            'run:TASK-1',
            2,
            'sha256:plan',
            'implementation',
            3,
            'codex',
            'sha256:workspace',
            'submission-1',
            $status,
            process: $process,
        );
    }
}
