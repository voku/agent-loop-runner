<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\Config\RunnerConfig;

final class RunnerConfigTrustTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-config-trust-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
        $this->git(['init', '--quiet']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTrackedRunnerConfigIsRefusedBecauseItCanStartCredentialBearingCommands(): void
    {
        mkdir($this->root . '/.agent-loop-runner', 0o700);
        $config = $this->root . '/.agent-loop-runner/config.json';
        file_put_contents($config, json_encode([
            'schema_version' => 1,
            'hosts' => ['codex' => ['binary' => 'codex']],
        ], JSON_THROW_ON_ERROR));
        $this->git(['add', '--', '.agent-loop-runner/config.json']);

        try {
            RunnerConfig::load($this->root);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tracked', strtolower($exception->getMessage()));

            return;
        }

        self::fail('Repository-tracked runner configuration must not be executed as operator configuration.');
    }

    public function testUntrackedRunnerConfigRemainsAvailableAsLocalOperatorConfiguration(): void
    {
        mkdir($this->root . '/.agent-loop-runner', 0o700);
        file_put_contents($this->root . '/.agent-loop-runner/config.json', json_encode([
            'schema_version' => 1,
            'hosts' => ['codex' => ['binary' => '/opt/operator/codex']],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('/opt/operator/codex', RunnerConfig::load($this->root)->binary('codex'));
    }

    public function testTrackedConfigInsideNestedRepositoryIsAlsoRefused(): void
    {
        $directory = $this->root . '/.agent-loop-runner';
        mkdir($directory, 0o700);
        $this->git(['-C', '.agent-loop-runner', 'init', '--quiet']);
        file_put_contents($directory . '/config.json', json_encode([
            'schema_version' => 1,
            'hosts' => ['codex' => ['binary' => 'codex']],
        ], JSON_THROW_ON_ERROR));
        $this->git(['-C', '.agent-loop-runner', 'add', '--', 'config.json']);

        try {
            RunnerConfig::load($this->root);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tracked', strtolower($exception->getMessage()));

            return;
        }

        self::fail('A nested tracked runner config must not bypass the operator ownership check.');
    }

    public function testSymlinkedConfigDirectoryIsRefused(): void
    {
        $outside = $this->root . '/outside-config';
        mkdir($outside, 0o700);
        file_put_contents($outside . '/config.json', json_encode([
            'schema_version' => 1,
            'hosts' => ['codex' => ['binary' => 'codex']],
        ], JSON_THROW_ON_ERROR));
        self::assertTrue(symlink($outside, $this->root . '/.agent-loop-runner'));

        try {
            RunnerConfig::load($this->root);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symbolic-link', $exception->getMessage());

            return;
        }

        self::fail('A repository symlink must not redirect the config trust check outside the checkout.');
    }

    public function testSymlinkedConfigFileIsRefused(): void
    {
        mkdir($this->root . '/.agent-loop-runner', 0o700);
        $outside = $this->root . '/operator-config.json';
        file_put_contents($outside, json_encode([
            'schema_version' => 1,
            'hosts' => ['codex' => ['binary' => 'codex']],
        ], JSON_THROW_ON_ERROR));
        self::assertTrue(symlink($outside, $this->root . '/.agent-loop-runner/config.json'));

        try {
            RunnerConfig::load($this->root);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symbolic-link', $exception->getMessage());

            return;
        }

        self::fail('A symlinked config leaf must not bypass the operator ownership check.');
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        $process = proc_open(
            ['git', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH')],
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, $stderr);
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
}
