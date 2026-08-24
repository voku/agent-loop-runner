<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Process\EnvironmentProjector;

final class ConfigEnvironmentTest extends TestCase
{
    public function testDefaultsKeepProviderChoiceExplicitAndResolvable(): void
    {
        $config = RunnerConfig::defaults();

        self::assertSame('codex', $config->hostForRole('builder'));
        self::assertSame('claude', $config->hostForRole('reviewer'));
        self::assertSame('codex', $config->binary('codex'));
        self::assertGreaterThan(0, $config->timeoutSeconds);
    }

    public function testUnknownRoleFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No runner host is configured');

        RunnerConfig::defaults()->hostForRole('invented-role');
    }

    public function testHostWithoutBuiltInAdapterFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runner host has no built-in adapter: custom');

        new RunnerConfig(
            ['custom' => ['binary' => 'custom']],
            ['builder' => 'custom'],
            30,
            ['PATH'],
        );
    }

    public function testEnvironmentProjectionIncludesOnlyNamedVariables(): void
    {
        $allowed = 'AGENT_LOOP_RUNNER_ALLOWED_' . bin2hex(random_bytes(4));
        $secret = 'AGENT_LOOP_RUNNER_SECRET_' . bin2hex(random_bytes(4));
        putenv($allowed . '=visible');
        putenv($secret . '=must-not-project');

        try {
            $projected = (new EnvironmentProjector())->project([$allowed]);

            self::assertSame([$allowed => 'visible'], $projected);
            self::assertArrayNotHasKey($secret, $projected);
            self::assertStringNotContainsString('must-not-project', json_encode($projected, JSON_THROW_ON_ERROR));
        } finally {
            putenv($allowed);
            putenv($secret);
        }
    }

    public function testLoadStripsUtf8Bom(): void
    {
        $dir = sys_get_temp_dir() . '/runner-config-test-' . bin2hex(random_bytes(4));
        mkdir($dir . '/.agent-loop-runner', 0777, true);
        $json = "\xEF\xBB\xBF" . json_encode([
            'schema_version' => 1,
            'hosts' => ['codex' => ['binary' => 'codex-custom']],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($dir . '/.agent-loop-runner/config.json', $json);

        try {
            $config = RunnerConfig::load($dir);
            self::assertSame('codex-custom', $config->binary('codex'));
        } finally {
            unlink($dir . '/.agent-loop-runner/config.json');
            rmdir($dir . '/.agent-loop-runner');
            rmdir($dir);
        }
    }
}

