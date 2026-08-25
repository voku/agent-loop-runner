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
        $authority = new ExecutionProjection(
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
        $observation = new RuntimeAttempt(
            'TASK-1',
            'run:TASK-1',
            2,
            'sha256:plan',
            'implementation',
            3,
            'codex',
            'sha256:workspace',
            'submission-1',
            AttemptStatus::Prepared,
        );

        $status = new RunnerStatus($authority, $observation);
        $serialized = $status->toArray();

        self::assertSame($authority, $status->authority);
        self::assertSame($observation, $status->observation);
        self::assertSame('implementation', $serialized['authority']['current_stage_id']);
        self::assertSame('codex', $serialized['runner_observation']['host_id'] ?? null);
        self::assertArrayNotHasKey('host_id', $serialized['authority']);
        self::assertArrayNotHasKey('complete', $serialized['runner_observation'] ?? []);
    }
}
