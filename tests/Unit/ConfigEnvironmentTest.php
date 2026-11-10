<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\Config\ModelPolicy;
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

    public function testModelPolicyIsResolvedPerRoleWithoutChangingWorkflowProfile(): void
    {
        $config = new RunnerConfig(
            ['codex' => ['binary' => 'codex']],
            ['investigator' => 'codex'],
            30,
            ['PATH'],
            ['investigator' => new ModelPolicy('gpt-5.6-luna', 'low')],
        );

        $policy = $config->modelPolicyForRole('investigator');
        self::assertNotNull($policy);
        self::assertSame('gpt-5.6-luna', $policy->model);
        self::assertSame('low', $policy->reasoningEffort);
        self::assertNull($config->modelPolicyForRole('unknown'));
    }

    public function testModelPoliciesLoadFromRunnerConfiguration(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-runner-config-' . bin2hex(random_bytes(5));
        $directory = $root . '/.agent-loop-runner';
        self::assertTrue(mkdir($directory, 0o700, true));
        $this->git($root, ['init', '--quiet']);
        self::assertNotFalse(file_put_contents($directory . '/config.json', json_encode([
            'schema_version' => 1,
            'execution' => [
                'model_policies' => [
                    'investigator' => [
                        'model' => 'gpt-5.6-luna',
                        'reasoning_effort' => 'low',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        try {
            $policy = RunnerConfig::load($root)->modelPolicyForRole('investigator');
            self::assertNotNull($policy);
            self::assertSame('gpt-5.6-luna', $policy->model);
            self::assertSame('low', $policy->reasoningEffort);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /** @param list<string> $arguments */
    private function git(string $root, array $arguments): void
    {
        $process = proc_open(
            ['git', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['PATH' => (string) getenv('PATH')],
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
    }

    private function removeDirectory(string $path): void
    {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            if (is_link($child) || is_file($child)) {
                unlink($child);
            } elseif (is_dir($child)) {
                $this->removeDirectory($child);
            }
        }
        rmdir($path);
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
