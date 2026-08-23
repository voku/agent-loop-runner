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
}
