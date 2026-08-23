<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Execution;

use JsonException;

final readonly class CompletionEnvelopeParser
{
    public const string DEFAULT_MARKER = 'AGENT_LOOP_STAGE_RESULT';
    private const int MAX_SUMMARY_BYTES = 4_000;
    private const int MAX_REFERENCE_BYTES = 1_000;
    private const int MAX_REFERENCES = 100;

    /**
     * @param non-empty-list<non-empty-string> $acceptedOutcomes
     * @param non-empty-string $marker
     */
    public function parse(string $stdout, array $acceptedOutcomes, string $marker = self::DEFAULT_MARKER): CompletionEnvelope
    {
        $lines = preg_split('/\R/u', $stdout);
        if ($lines === false) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: stdout is not valid line-oriented UTF-8.');
        }
        $nonEmpty = [];
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $nonEmpty[] = $line;
            }
        }
        if ($nonEmpty === []) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: stdout has no completion envelope.');
        }

        $prefix = $marker . ' ';
        $markerLines = array_values(array_filter(
            $nonEmpty,
            static fn (string $line): bool => str_starts_with($line, $marker),
        ));
        if (count($markerLines) !== 1 || !str_starts_with($nonEmpty[array_key_last($nonEmpty)], $prefix)) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: exactly one marker must be the final non-empty stdout line.');
        }
        $line = $nonEmpty[array_key_last($nonEmpty)];
        if (!str_starts_with($line, $prefix) || str_starts_with($line, $prefix . ' ')) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: completion marker syntax is invalid.');
        }
        $json = substr($line, strlen($prefix));
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: malformed completion JSON.', 0, $exception);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: completion payload must be one JSON object.');
        }
        $expectedKeys = ['artifact_references', 'outcome', 'summary', 'validation_references'];
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== $expectedKeys) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: completion payload fields do not match the protocol.');
        }

        $outcome = $payload['outcome'];
        if (!is_string($outcome) || $outcome === '' || !in_array($outcome, $acceptedOutcomes, true)) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: outcome is missing or not accepted for this stage.');
        }
        $summary = $payload['summary'];
        if (!is_string($summary)) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: summary must be a string.');
        }
        $summary = trim($summary);
        if ($summary === '' || strlen($summary) > self::MAX_SUMMARY_BYTES) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: summary must be non-empty and bounded.');
        }

        return new CompletionEnvelope(
            $outcome,
            $summary,
            $this->references($payload['artifact_references'], 'artifact_references'),
            $this->references($payload['validation_references'], 'validation_references'),
        );
    }

    /** @return list<non-empty-string> */
    private function references(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_REFERENCES) {
            throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: ' . $field . ' must be a bounded list.');
        }
        $references = [];
        foreach ($value as $reference) {
            if (!is_string($reference)) {
                throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: ' . $field . ' entries must be strings.');
            }
            $reference = trim($reference);
            if ($reference === '' || strlen($reference) > self::MAX_REFERENCE_BYTES) {
                throw new InvalidCompletionEnvelope('INVALID_STAGE_RESULT: ' . $field . ' entries must be non-empty and bounded.');
            }
            $references[] = $reference;
        }

        return $references;
    }
}
