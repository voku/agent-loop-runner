<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class HostCapacityReport
{
    /**
     * @param list<array{label: string, remaining_ratio: float, reset_at: int|null}> $rawMetrics
     */
    public function __construct(
        public ?float $remainingRatio,
        public ?int $resetAt,
        public ?string $summary,
        public array $rawMetrics = [],
    ) {
    }

    public function usageRatio(): ?float
    {
        return $this->remainingRatio !== null ? max(0.0, min(1.0, 1.0 - $this->remainingRatio)) : null;
    }

    public function isNearLimit(float $threshold = 0.95): bool
    {
        $usage = $this->usageRatio();

        return $usage !== null && $usage >= $threshold;
    }
}
