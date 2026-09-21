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
     * @param array<string, array{binary: non-empty-string, resource_command?: list<non-empty-string>, fallback?: non-empty-string}> $hosts
     * @param array<string, non-empty-string> $roles
     * @param list<non-empty-string> $environmentAllowlist
     * @param array<string, ModelPolicy> $modelPolicies
     * @param array<string, non-empty-string> $roleFallbacks
     */
    public function __construct(
        public array $hosts,
        public array $roles,
        public int $timeoutSeconds,
        public array $environmentAllowlist,
        public array $modelPolicies = [],
        public array $roleFallbacks = [],
        public float $quotaUsageThreshold = 0.95,
    ) {
        if ($this->timeoutSeconds < 1) {
            throw new RuntimeException('Runner timeout must be a positive integer.');
        }
        if ($this->quotaUsageThreshold <= 0.0 || $this->quotaUsageThreshold > 1.0) {
            throw new RuntimeException('Runner quota_usage_threshold must be a float between 0.0 and 1.0.');
        }
        foreach ($this->hosts as $hostId => $hostConfig) {
            if (!in_array($hostId, self::SUPPORTED_HOST_IDS, true)) {
                throw new RuntimeException('Runner host has no built-in adapter: ' . $hostId . '.');
            }
            $fallback = $hostConfig['fallback'] ?? null;
            if ($fallback !== null && (!in_array($fallback, self::SUPPORTED_HOST_IDS, true) || !isset($this->hosts[$fallback]))) {
                throw new RuntimeException('Runner host ' . $hostId . ' references unknown fallback host ' . $fallback . '.');
            }
            if ($fallback === $hostId) {
                throw new RuntimeException('Runner host ' . $hostId . ' cannot use itself as a fallback host.');
            }
        }
        foreach ($this->roles as $roleId => $hostId) {
            if (!isset($this->hosts[$hostId])) {
                throw new RuntimeException('Runner role ' . $roleId . ' references unknown host ' . $hostId . '.');
            }
        }
        foreach ($this->roleFallbacks as $roleId => $fallbackHostId) {
            if (!isset($this->roles[$roleId])) {
                throw new RuntimeException('Runner role fallback references unknown role ' . $roleId . '.');
            }
            if (!isset($this->hosts[$fallbackHostId])) {
                throw new RuntimeException('Runner role fallback for ' . $roleId . ' references unknown host ' . $fallbackHostId . '.');
            }
            if (array_key_exists($roleId, $this->roles) && $this->roles[$roleId] === $fallbackHostId) {
                throw new RuntimeException('Runner role fallback for ' . $roleId . ' cannot use its primary host.');
            }
        }
        foreach ($this->modelPolicies as $roleId => $policy) {
            if (trim($roleId) === '' || !isset($this->roles[$roleId])) {
                throw new RuntimeException('Runner model policies require a role id and ModelPolicy value.');
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
        $roleFallbacks = self::roleFallbacks($data['role_fallbacks'] ?? null, $defaults->roleFallbacks);
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
        $modelPolicies = self::modelPolicies(
            $execution['model_policies'] ?? null,
            $defaults->modelPolicies,
        );
        $quotaThreshold = $execution['quota_usage_threshold'] ?? $defaults->quotaUsageThreshold;
        if (!is_float($quotaThreshold) && !is_int($quotaThreshold)) {
            throw new RuntimeException('Runner quota_usage_threshold must be a float between 0.0 and 1.0.');
        }
        $quotaThreshold = (float) $quotaThreshold;

        return new self($hosts, $roles, $timeout, $allowlist, $modelPolicies, $roleFallbacks, $quotaThreshold);
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

    public function modelPolicyForRole(string $roleId): ?ModelPolicy
    {
        return $this->modelPolicies[$roleId] ?? null;
    }

    public function fallbackHostForRole(string $roleId): ?string
    {
        if (isset($this->roleFallbacks[$roleId])) {
            return $this->roleFallbacks[$roleId];
        }

        $primaryHost = $this->roles[$roleId] ?? null;
        if ($primaryHost !== null && isset($this->hosts[$primaryHost]['fallback'])) {
            return $this->hosts[$primaryHost]['fallback'];
        }

        return null;
    }

    /**
     * @return list<non-empty-string>|null
     */
    public function resourceCommandForHost(string $hostId): ?array
    {
        return $this->hosts[$hostId]['resource_command'] ?? null;
    }

    public function fallbackForHost(string $hostId): ?string
    {
        return $this->hosts[$hostId]['fallback'] ?? null;
    }

    /**
     * @param array<string, array{binary: non-empty-string, resource_command?: list<non-empty-string>, fallback?: non-empty-string}> $defaults
     * @return array<string, array{binary: non-empty-string, resource_command?: list<non-empty-string>, fallback?: non-empty-string}>
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
            $hostConfig = ['binary' => $binary];
            if (isset($entry['resource_command'])) {
                $hostConfig['resource_command'] = self::stringList($entry['resource_command'], 'hosts.' . $id . '.resource_command');
            }
            if (isset($entry['fallback'])) {
                if (!is_string($entry['fallback'])) {
                    throw new RuntimeException('Runner host ' . $id . ' fallback must be a non-empty string.');
                }
                $fallback = trim($entry['fallback']);
                if ($fallback === '') {
                    throw new RuntimeException('Runner host ' . $id . ' fallback must be a non-empty string.');
                }
                $hostConfig['fallback'] = $fallback;
            }
            $hosts[$id] = $hostConfig;
        }

        return $hosts;
    }

    /**
     * @param array<string, non-empty-string> $defaults
     * @return array<string, non-empty-string>
     */
    private static function roleFallbacks(mixed $value, array $defaults): array
    {
        if ($value === null) {
            return $defaults;
        }
        if (!is_array($value)) {
            throw new RuntimeException('Runner config role_fallbacks must be an object.');
        }
        $fallbacks = $defaults;
        foreach ($value as $role => $host) {
            if (!is_string($role) || !is_string($host)) {
                throw new RuntimeException('Runner role fallback mappings require non-empty string keys and values.');
            }
            $role = trim($role);
            $host = trim($host);
            if ($role === '' || $host === '') {
                throw new RuntimeException('Runner role fallback mappings require non-empty string keys and values.');
            }
            $fallbacks[$role] = $host;
        }

        return $fallbacks;
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

    /**
     * @param array<string, ModelPolicy> $defaults
     * @return array<string, ModelPolicy>
     */
    private static function modelPolicies(mixed $value, array $defaults): array
    {
        if ($value === null) {
            return $defaults;
        }
        if (!is_array($value)) {
            throw new RuntimeException('Runner config execution.model_policies must be an object.');
        }
        $policies = $defaults;
        foreach ($value as $role => $entry) {
            if (!is_string($role) || trim($role) === '' || !is_array($entry)) {
                throw new RuntimeException('Runner model policy entries require a role id and object value.');
            }
            $model = $entry['model'] ?? null;
            $effort = $entry['reasoning_effort'] ?? null;
            if (!is_string($model) || trim($model) === '') {
                throw new RuntimeException('Runner model policy ' . $role . ' requires a non-empty model.');
            }
            if ($effort !== null && (!is_string($effort) || trim($effort) === '')) {
                throw new RuntimeException('Runner model policy ' . $role . ' reasoning_effort must be a non-empty string.');
            }
            try {
                $policies[trim($role)] = new ModelPolicy(trim($model), is_string($effort) ? trim($effort) : null);
            } catch (\InvalidArgumentException $exception) {
                throw new RuntimeException('Invalid runner model policy ' . $role . ': ' . $exception->getMessage(), 0, $exception);
            }
        }

        return $policies;
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
