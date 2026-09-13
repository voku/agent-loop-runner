<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Application;

final readonly class RequiredHostObservation
{
    public function __construct(
        public string $roleId,
        public string $hostId,
        public bool $available,
        public ?string $version,
    ) {
    }

    /**
     * @return array{
     *     role_id: string,
     *     host_id: string,
     *     available: bool,
     *     version: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'role_id' => $this->roleId,
            'host_id' => $this->hostId,
            'available' => $this->available,
            'version' => $this->version,
        ];
    }
}
