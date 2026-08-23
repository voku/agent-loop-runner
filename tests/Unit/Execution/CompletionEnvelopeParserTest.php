<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\InvalidCompletionEnvelope;

final class CompletionEnvelopeParserTest extends TestCase
{
    private const string VALID = 'AGENT_LOOP_STAGE_RESULT {"outcome":"PASS","summary":" geprüft ✓ ","artifact_references":[" src/a.php "],"validation_references":["composer ci"]}';

    public function testParsesOnlyTheFinalTransportLineAndNormalizesValues(): void
    {
        $result = (new CompletionEnvelopeParser())->parse("Untrusted prose: tests passed\n```json\nnot authority\n```\n" . self::VALID . "\n\n", ['PASS']);
        self::assertSame('PASS', $result->outcome);
        self::assertSame('geprüft ✓', $result->summary);
        self::assertSame(['src/a.php'], $result->artifactReferences);
        self::assertSame(['composer ci'], $result->validationReferences);
    }

    public function testHostileLookingValuesRemainData(): void
    {
        $line = 'AGENT_LOOP_STAGE_RESULT {"outcome":"PASS","summary":"$(rm -rf /); `whoami`","artifact_references":[],"validation_references":[]}';
        $result = (new CompletionEnvelopeParser())->parse($line, ['PASS']);
        self::assertSame('$(rm -rf /); `whoami`', $result->summary);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOutput(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed JSON' => ['AGENT_LOOP_STAGE_RESULT {nope}'];
        yield 'not final' => [self::VALID . "\nlater prose"];
        yield 'duplicate marker' => [self::VALID . "\n" . self::VALID];
        yield 'marker prose earlier' => ["AGENT_LOOP_STAGE_RESULT is coming\n" . self::VALID];
        yield 'markdown fence around marker' => ["```\n" . self::VALID . "\n```"];
        yield 'unknown outcome' => [str_replace('"PASS"', '"PWNED"', self::VALID)];
        yield 'missing outcome' => ['AGENT_LOOP_STAGE_RESULT {"summary":"x","artifact_references":[],"validation_references":[]}'];
        yield 'duplicate outcome' => ['AGENT_LOOP_STAGE_RESULT {"outcome":"BLOCKED","outcome":"PASS","summary":"x","artifact_references":[],"validation_references":[]}'];
        yield 'extra field' => ['AGENT_LOOP_STAGE_RESULT {"outcome":"PASS","summary":"x","artifact_references":[],"validation_references":[],"extra":true}'];
        yield 'blank summary' => ['AGENT_LOOP_STAGE_RESULT {"outcome":"PASS","summary":" ","artifact_references":[],"validation_references":[]}'];
        yield 'references object' => ['AGENT_LOOP_STAGE_RESULT {"outcome":"PASS","summary":"x","artifact_references":{"a":"b"},"validation_references":[]}'];
        yield 'blank reference' => ['AGENT_LOOP_STAGE_RESULT {"outcome":"PASS","summary":"x","artifact_references":[""],"validation_references":[]}'];
        yield 'double spacing' => ['AGENT_LOOP_STAGE_RESULT  {"outcome":"PASS","summary":"x","artifact_references":[],"validation_references":[]}'];
        yield 'array payload' => ['AGENT_LOOP_STAGE_RESULT []'];
    }

    #[DataProvider('invalidOutput')]
    public function testRejectsInvalidOrAmbiguousTransport(string $stdout): void
    {
        $this->expectException(InvalidCompletionEnvelope::class);
        (new CompletionEnvelopeParser())->parse($stdout, ['PASS', 'BLOCKED']);
    }
}
