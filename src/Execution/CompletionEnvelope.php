<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Execution;

final readonly class CompletionEnvelope
{
    /**
     * @param non-empty-string $outcome
     * @param non-empty-string $summary
     * @param list<non-empty-string> $artifactReferences
     * @param list<non-empty-string> $validationReferences
     */
    public function __construct(
        public string $outcome,
        public string $summary,
        public array $artifactReferences,
        public array $validationReferences,
    ) {
    }

    /** @return array{outcome: non-empty-string, summary: non-empty-string, artifact_references: list<non-empty-string>, validation_references: list<non-empty-string>} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'summary' => $this->summary,
            'artifact_references' => $this->artifactReferences,
            'validation_references' => $this->validationReferences,
        ];
    }
}
