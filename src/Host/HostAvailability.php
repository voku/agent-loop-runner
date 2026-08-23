<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class HostAvailability
{
    public function __construct(
        public string $hostId,
        public ?string $binaryPath,
        public ?string $version,
        public ?string $failure,
    ) {
    }

    public function available(): bool
    {
        return $this->binaryPath !== null && $this->failure === null;
    }
}
