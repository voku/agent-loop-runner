<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Execution;

use voku\AgentLoop\Execution\StageOutcome;

final readonly class CompletionEnvelope
{
    /**
     * @param list<non-empty-string> $artifactReferences
     * @param list<non-empty-string> $validationReferences
     */
    public function __construct(
        public StageOutcome $outcome,
        public string $summary,
        public array $artifactReferences,
        public array $validationReferences,
    ) {
    }
}
