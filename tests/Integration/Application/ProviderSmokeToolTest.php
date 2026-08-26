<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;

final class ProviderSmokeToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-provider-smoke-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/bin', 0o775, true));
        self::assertTrue(mkdir($this->root . '/repo', 0o775, true));

        $fakeCodex = <<<'SH'
#!/bin/sh
if [ "${1:-}" = "--version" ]; then
    printf 'codex-smoke 1.0\n'
    exit 0
fi
if [ "${1:-}" = "exec" ]; then
    cat >/dev/null
    printf 'PROVIDER_SMOKE_OK\n'
    exit 0
fi
exit 64
SH;
        file_put_contents($this->root . '/bin/codex', $fakeCodex);
        chmod($this->root . '/bin/codex', 0o755);

        $this->runGit(['init', '-q']);
        $this->runGit(['config', 'user.name', 'provider-smoke']);
        $this->runGit(['config', 'user.email', 'provider-smoke@example.invalid']);
        file_put_contents($this->root . '/repo/README.md', "fixture\n");
        $this->runGit(['add', 'README.md']);
        $this->runGit(['commit', '-qm', 'fixture']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testSmokeExecutesConfiguredAdapterAndKeepsEvidenceBounded(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $path = $this->root . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: '');
        $process = proc_open(
            [PHP_BINARY, $repositoryRoot . '/tools/provider-smoke.php', 'codex', $this->root . '/repo'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repositoryRoot,
            ['PATH' => $path, 'HOME' => getenv('HOME') ?: $this->root],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame('', $stderr);
        self::assertSame(0, $exit);
        $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('PASS', $result['status'] ?? null);
        self::assertSame('codex', $result['host'] ?? null);
        self::assertSame('codex-smoke 1.0', $result['version'] ?? null);
        self::assertTrue($result['marker_observed'] ?? false);
        self::assertTrue($result['working_tree_clean'] ?? false);
        self::assertArrayHasKey('stdout_sha256', $result);
        self::assertArrayNotHasKey('stdout', $result);
        self::assertSame('', $this->gitOutput(['status', '--porcelain=v1']));
    }

    /** @param list<string> $arguments */
    private function runGit(array $arguments): void
    {
        $command = ['git', '-C', $this->root . '/repo', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, (string) $stderr . (string) $stdout);
    }

    /** @param list<string> $arguments */
    private function gitOutput(array $arguments): string
    {
        $command = ['git', '-C', $this->root . '/repo', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, (string) $stderr);

        return trim((string) $stdout);
    }
}
