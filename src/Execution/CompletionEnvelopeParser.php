<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Execution;

use JsonException;
use RuntimeException;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;

final readonly class CompletionEnvelopeParser
{
    private const int MAX_SUMMARY_BYTES = 4096;
    private const int MAX_REFERENCES = 64;
    private const int MAX_REFERENCE_BYTES = 2048;

    public function parse(StageExecutionBundle $bundle, string $stdout): ParsedStageCompletion
    {
        $marker = trim($bundle->completionMarker);
        if ($marker === '') {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion marker is empty.');
        }

        $nonEmptyLines = array_values(array_filter(
            preg_split('/\R/u', $stdout) ?: [],
            static fn (string $line): bool => trim($line) !== '',
        ));
        if ($nonEmptyLines === []) {
            throw new RuntimeException('INVALID_STAGE_RESULT: host output contains no completion envelope.');
        }

        $markerPrefix = $marker . ' ';
        $matchingLines = [];
        foreach ($nonEmptyLines as $index => $line) {
            if (str_starts_with(trim($line), $markerPrefix)) {
                $matchingLines[] = $index;
            }
        }
        if (count($matchingLines) !== 1) {
            throw new RuntimeException('INVALID_STAGE_RESULT: host output must contain exactly one completion envelope.');
        }

        $lastIndex = array_key_last($nonEmptyLines);
        if ($matchingLines[0] !== $lastIndex) {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion envelope must be the final non-empty stdout line.');
        }

        $line = trim($nonEmptyLines[$lastIndex]);
        $json = substr($line, strlen($markerPrefix));
        if ($json === '') {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion envelope JSON is empty.');
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion envelope JSON is malformed.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion envelope must be a JSON object.');
        }

        $allowedKeys = ['outcome', 'summary', 'artifact_references', 'validation_references'];
        $unexpectedKeys = array_diff(array_keys($decoded), $allowedKeys);
        if ($unexpectedKeys !== []) {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion envelope contains unsupported fields.');
        }

        $outcomeValue = $decoded['outcome'] ?? null;
        if (!is_string($outcomeValue)) {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion outcome must be a string.');
        }
        $outcome = StageOutcome::tryFrom($outcomeValue);
        if ($outcome === null || !$this->isAcceptedOutcome($outcome, $bundle->acceptedOutcomes)) {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion outcome is not accepted for this stage.');
        }

        $summary = $decoded['summary'] ?? null;
        if (!is_string($summary)) {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion summary must be a string.');
        }
        $summary = trim($summary);
        if ($summary === '' || strlen($summary) > self::MAX_SUMMARY_BYTES) {
            throw new RuntimeException('INVALID_STAGE_RESULT: completion summary must be non-empty and bounded.');
        }

        return new ParsedStageCompletion(
            $outcome,
            $summary,
            $this->references($decoded['artifact_references'] ?? null, 'artifact'),
            $this->references($decoded['validation_references'] ?? null, 'validation'),
        );
    }

    /** @param list<StageOutcome> $acceptedOutcomes */
    private function isAcceptedOutcome(StageOutcome $outcome, array $acceptedOutcomes): bool
    {
        foreach ($acceptedOutcomes as $acceptedOutcome) {
            if ($acceptedOutcome === $outcome) {
                return true;
            }
        }

        return false;
    }

    /** @return list<non-empty-string> */
    private function references(mixed $value, string $kind): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_REFERENCES) {
            throw new RuntimeException('INVALID_STAGE_RESULT: ' . $kind . ' references must be a bounded list.');
        }

        $references = [];
        foreach ($value as $reference) {
            if (!is_string($reference)) {
                throw new RuntimeException('INVALID_STAGE_RESULT: ' . $kind . ' references must contain strings.');
            }
            $reference = trim($reference);
            if ($reference === '' || strlen($reference) > self::MAX_REFERENCE_BYTES) {
                throw new RuntimeException('INVALID_STAGE_RESULT: ' . $kind . ' references must be non-empty and bounded.');
            }
            $references[] = $reference;
        }

        return $references;
    }
}
