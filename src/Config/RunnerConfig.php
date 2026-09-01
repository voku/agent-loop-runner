<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Config;

use JsonException;
use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;

final readonly class RunnerConfig
{
    /** @var list<non-empty-string> */
    private const array SUPPORTED_HOST_IDS = ['codex', 'claude', 'opencode', 'agy'];

    /**
     * @param array<string, array{binary: non-empty-string}> $hosts
     * @param array<string, non-empty-string> $roles
     * @param list<non-empty-string> $environmentAllowlist
     */
    public function __construct(
        public array $hosts,
        public array $roles,
        public int $timeoutSeconds,
        public array $environmentAllowlist,
    ) {
        if ($this->timeoutSeconds < 1) {
            throw new RuntimeException('Runner timeout must be a positive integer.');
        }
        foreach (array_keys($this->hosts) as $hostId) {
            if (!in_array($hostId, self::SUPPORTED_HOST_IDS, true)) {
                throw new RuntimeException('Runner host has no built-in adapter: ' . $hostId . '.');
            }
        }
        foreach ($this->roles as $roleId => $hostId) {
            if (!isset($this->hosts[$hostId])) {
                throw new RuntimeException('Runner role ' . $roleId . ' references unknown host ' . $hostId . '.');
            }
        }
    }

    public static function load(string $projectRoot): self
    {
        $path = (new RunnerLayout($projectRoot))->config();
        if (!is_file($path)) {
            return self::defaults();
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read runner config: ' . $path);
        }
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid runner config JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Runner config requires schema_version 1.');
        }

        $defaults = self::defaults();
        $hosts = self::hosts($data['hosts'] ?? null, $defaults->hosts);
        $roles = self::roles($data['roles'] ?? null, $defaults->roles);
        $execution = $data['execution'] ?? [];
        if (!is_array($execution)) {
            throw new RuntimeException('Runner config execution must be an object.');
        }
        $timeout = $execution['timeout_seconds'] ?? $defaults->timeoutSeconds;
        if (!is_int($timeout) || $timeout < 1) {
            throw new RuntimeException('Runner timeout_seconds must be a positive integer.');
        }
        $allowlist = self::stringList(
            $execution['environment_allowlist'] ?? $defaults->environmentAllowlist,
            'execution.environment_allowlist',
        );

        return new self($hosts, $roles, $timeout, $allowlist);
    }

    public static function defaults(): self
    {
        return new self(
            [
                'codex' => ['binary' => 'codex'],
                'claude' => ['binary' => 'claude'],
                'opencode' => ['binary' => 'opencode'],
                'agy' => ['binary' => 'agy'],
            ],
            [
                'investigator' => 'codex',
                'builder' => 'codex',
                'reviewer' => 'claude',
                'correctness-review' => 'claude',
                'architecture-review' => 'claude',
                'hardening' => 'codex',
                'independent-verification' => 'claude',
                'blindspot-review' => 'claude',
            ],
            1800,
            [
                'PATH', 'HOME', 'USER', 'LOGNAME', 'TMPDIR', 'TEMP', 'TMP',
                'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_DATA_HOME',
                'CODEX_HOME', 'OPENAI_API_KEY',
                'ANTHROPIC_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN',
                'OPENCODE_CONFIG', 'OPENCODE_CONFIG_DIR',
                'ANTIGRAVITY_LS_ADDRESS', 'ANTIGRAVITY_AGENTAPI_EXE', 'ANTIGRAVITY_CSRF_TOKEN',
                'ANTIGRAVITY_PROJECT_ID', 'GEMINI_API_KEY', 'GOOGLE_API_KEY',
            ],
        );
    }

    public function hostForRole(string $roleId): string
    {
        $host = $this->roles[$roleId] ?? null;
        if ($host === null) {
            throw new RuntimeException('No runner host is configured for role ' . $roleId . '.');
        }
        if (!isset($this->hosts[$host])) {
            throw new RuntimeException('Runner role ' . $roleId . ' references unknown host ' . $host . '.');
        }

        return $host;
    }

    public function binary(string $hostId): string
    {
        $host = $this->hosts[$hostId] ?? null;
        if ($host === null) {
            throw new RuntimeException('Runner host has no binary: ' . $hostId);
        }

        return trim($host['binary']);
    }

    /**
     * @param array<string, array{binary: non-empty-string}> $defaults
     * @return array<string, array{binary: non-empty-string}>
     */
    private static function hosts(mixed $value, array $defaults): array
    {
        if ($value === null) {
            return $defaults;
        }
        if (!is_array($value)) {
            throw new RuntimeException('Runner config hosts must be an object.');
        }
        $hosts = $defaults;
        foreach ($value as $id => $entry) {
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9_-]*$/', $id) !== 1 || !is_array($entry)) {
                throw new RuntimeException('Runner host entries require a stable lowercase id and object value.');
            }
            if (!in_array($id, self::SUPPORTED_HOST_IDS, true)) {
                throw new RuntimeException('Runner host has no built-in adapter: ' . $id . '.');
            }
            $binary = $entry['binary'] ?? ($hosts[$id]['binary'] ?? null);
            if (!is_string($binary)) {
                throw new RuntimeException('Runner host ' . $id . ' requires a non-empty binary.');
            }
            $binary = trim($binary);
            if ($binary === '') {
                throw new RuntimeException('Runner host ' . $id . ' requires a non-empty binary.');
            }
            $hosts[$id] = ['binary' => $binary];
        }

        return $hosts;
    }

    /**
     * @param array<string, non-empty-string> $defaults
     * @return array<string, non-empty-string>
     */
    private static function roles(mixed $value, array $defaults): array
    {
        if ($value === null) {
            return $defaults;
        }
        if (!is_array($value)) {
            throw new RuntimeException('Runner config roles must be an object.');
        }
        $roles = $defaults;
        foreach ($value as $role => $host) {
            if (!is_string($role) || !is_string($host)) {
                throw new RuntimeException('Runner role mappings require non-empty string keys and values.');
            }
            $role = trim($role);
            $host = trim($host);
            if ($role === '' || $host === '') {
                throw new RuntimeException('Runner role mappings require non-empty string keys and values.');
            }
            $roles[$role] = $host;
        }

        return $roles;
    }

    /** @return list<non-empty-string> */
    private static function stringList(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Runner config ' . $field . ' must be an array.');
        }
        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new RuntimeException('Runner config ' . $field . ' must contain non-empty strings.');
            }
            $trimmed = trim($entry);
            if ($trimmed === '') {
                throw new RuntimeException('Runner config ' . $field . ' must contain non-empty strings.');
            }
            $result[] = $trimmed;
        }

        return array_values(array_unique($result));
    }
}
