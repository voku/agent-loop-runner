<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\Config\RunnerConfig;

final class RunnerConfigQuotaTest extends TestCase
{
    public function testHostFallbackAndResourceCommandFromConfig(): void
    {
        $root = sys_get_temp_dir() . '/runner-config-quota-' . bin2hex(random_bytes(5));
        $dir = $root . '/.agent-loop-runner';
        mkdir($dir, 0o775, true);
        file_put_contents($dir . '/config.json', json_encode([
            'schema_version' => 1,
            'hosts' => [
                'codex' => [
                    'binary' => 'codex',
                    'resource_command' => ['codex-cli-usage', 'json'],
                    'fallback' => 'claude',
                ],
                'claude' => [
                    'binary' => 'claude',
                ],
            ],
            'roles' => [
                'builder' => 'codex',
                'reviewer' => 'claude',
            ],
            'role_fallbacks' => [
                'builder' => 'claude',
            ],
            'execution' => [
                'quota_usage_threshold' => 0.90,
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $config = RunnerConfig::load($root);
            self::assertSame(['codex-cli-usage', 'json'], $config->resourceCommandForHost('codex'));
            self::assertNull($config->resourceCommandForHost('claude'));
            self::assertSame('claude', $config->fallbackForHost('codex'));
            self::assertNull($config->fallbackForHost('claude'));
            self::assertSame('claude', $config->fallbackHostForRole('builder'));
            self::assertNull($config->fallbackHostForRole('reviewer'));
            self::assertSame(0.90, $config->quotaUsageThreshold);
        } finally {
            unlink($dir . '/config.json');
            rmdir($dir);
            rmdir($root);
        }
    }

    public function testHostFallbackUsedWhenRoleFallbackNotExplicit(): void
    {
        $config = new RunnerConfig(
            [
                'codex' => ['binary' => 'codex', 'fallback' => 'claude'],
                'claude' => ['binary' => 'claude'],
            ],
            ['builder' => 'codex'],
            1800,
            ['PATH'],
        );

        self::assertSame('claude', $config->fallbackHostForRole('builder'));
        self::assertNull($config->fallbackHostForRole('nonexistent'));
    }

    public function testUnknownHostFallbackFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('references unknown fallback host nonexistent');

        new RunnerConfig(
            [
                'codex' => ['binary' => 'codex', 'fallback' => 'nonexistent'],
            ],
            ['builder' => 'codex'],
            1800,
            ['PATH'],
        );
    }

    public function testUnknownRoleFallbackFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runner role fallback references unknown role unknown-role');

        new RunnerConfig(
            [
                'codex' => ['binary' => 'codex'],
                'claude' => ['binary' => 'claude'],
            ],
            ['builder' => 'codex'],
            1800,
            ['PATH'],
            [],
            ['unknown-role' => 'claude'],
        );
    }

    public function testInvalidQuotaThresholdFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runner quota_usage_threshold must be a float between 0.0 and 1.0.');

        new RunnerConfig(
            ['codex' => ['binary' => 'codex']],
            ['builder' => 'codex'],
            1800,
            ['PATH'],
            [],
            [],
            1.5,
        );
    }
}
