<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Config;

use InvalidArgumentException;

final readonly class ModelPolicy
{
    public function __construct(
        public string $model,
        public ?string $reasoningEffort = null,
    ) {
        if ($model === '') {
            throw new InvalidArgumentException('Model policy requires a non-empty model.');
        }
        if ($reasoningEffort !== null && preg_match('/^[a-z][a-z0-9_-]*$/', $reasoningEffort) !== 1) {
            throw new InvalidArgumentException('Model policy reasoning effort must be a safe lowercase identifier.');
        }
    }
}
