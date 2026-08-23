<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;

final class CompletionEnvelopeParserTest extends TestCase
{
    public function testParsesOnlyTheFinalBoundedAcceptedEnvelope(): void
    {
        $completion = (new CompletionEnvelopeParser())->parse(
            self::bundle([StageOutcome::COMPLETED, StageOutcome::BLOCKED]),
            "working\nstill working\nAGENT_LOOP_STAGE_RESULT {\"outcome\":\"completed\",\"summary\":\"Implemented safely\",\"artifact_references\":[\"artifact.json\"],\"validation_references\":[\"composer test\"]}\n",
        );

        self::assertSame(StageOutcome::COMPLETED, $completion->outcome);
        self::assertSame('Implemented safely', $completion->summary);
        self::assertSame(['artifact.json'], $completion->artifactReferences);
        self::assertSame(['composer test'], $completion->validationReferences);
    }

    public function testRejectsEnvelopeThatIsNotTheFinalNonEmptyLine(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('final non-empty stdout line');

        (new CompletionEnvelopeParser())->parse(
            self::bundle([StageOutcome::COMPLETED]),
            "AGENT_LOOP_STAGE_RESULT {\"outcome\":\"completed\",\"summary\":\"done\",\"artifact_references\":[],\"validation_references\":[]}\nextra prose\n",
        );
    }

    public function testRejectsMultipleCompletionMarkers(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly one completion envelope');

        (new CompletionEnvelopeParser())->parse(
            self::bundle([StageOutcome::COMPLETED]),
            "AGENT_LOOP_STAGE_RESULT {\"outcome\":\"completed\",\"summary\":\"first\",\"artifact_references\":[],\"validation_references\":[]}\nAGENT_LOOP_STAGE_RESULT {\"outcome\":\"completed\",\"summary\":\"second\",\"artifact_references\":[],\"validation_references\":[]}\n",
        );
    }

    public function testRejectsOutcomeOutsideTheBundleAllowlist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not accepted for this stage');

        (new CompletionEnvelopeParser())->parse(
            self::bundle([StageOutcome::PASS]),
            "AGENT_LOOP_STAGE_RESULT {\"outcome\":\"changes_required\",\"summary\":\"needs work\",\"artifact_references\":[],\"validation_references\":[]}\n",
        );
    }

    public function testRejectsMalformedOrUnexpectedEnvelopeShape(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported fields');

        (new CompletionEnvelopeParser())->parse(
            self::bundle([StageOutcome::COMPLETED]),
            "AGENT_LOOP_STAGE_RESULT {\"outcome\":\"completed\",\"summary\":\"done\",\"artifact_references\":[],\"validation_references\":[],\"authoritative\":true}\n",
        );
    }

    /** @param list<StageOutcome> $acceptedOutcomes */
    private static function bundle(array $acceptedOutcomes): StageExecutionBundle
    {
        return new StageExecutionBundle(
            taskId: 'TASK-1',
            runId: 'RUN-1',
            contractRevision: 1,
            executionPlanDigest: 'sha256:' . str_repeat('a', 64),
            stageId: 'builder',
            attempt: 1,
            kind: ExecutionStageKind::AGENT,
            roleId: 'builder',
            mayMutate: true,
            repositoryRoot: '/tmp/project',
            baseCommit: str_repeat('b', 40),
            candidateRevision: str_repeat('b', 40),
            contractSource: ['path' => '.agent-loop/contracts/TASK-1.json', 'sha256' => 'sha256:' . str_repeat('c', 64)],
            recallSource: null,
            allowedScope: ['src/'],
            requiredValidation: ['composer test'],
            priorHandoff: null,
            acceptedOutcomes: $acceptedOutcomes,
            completionMarker: 'AGENT_LOOP_STAGE_RESULT',
            prompt: 'Do the bounded stage and return the exact completion marker.',
        );
    }
}
