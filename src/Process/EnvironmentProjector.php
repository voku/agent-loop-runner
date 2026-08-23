<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

final readonly class EnvironmentProjector
{
    /**
     * @param list<string> $allowlist
     * @return array<string, string>
     */
    public function project(array $allowlist): array
    {
        $environment = [];
        foreach ($allowlist as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
