<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;

final class InstalledConsumerBinaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-consumer-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/vendor/voku/agent-loop-runner/bin', 0o775, true));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testDependencyBinaryFindsConsumerAutoloaderWithoutComposerProxyVariable(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $rootAutoload = $repositoryRoot . '/vendor/autoload.php';
        self::assertFileExists($rootAutoload);
        file_put_contents(
            $this->root . '/vendor/autoload.php',
            '<?php require ' . var_export($rootAutoload, true) . ';',
        );
        $binary = $this->root . '/vendor/voku/agent-loop-runner/bin/agent-loop-runner';
        self::assertTrue(copy($repositoryRoot . '/bin/agent-loop-runner', $binary));

        $process = proc_open(
            [PHP_BINARY, $binary],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame('', $stdout);
        self::assertSame(2, $exit);
        self::assertStringContainsString('Usage: agent-loop-runner', (string) $stderr);
        self::assertStringNotContainsString('Dependencies are not installed', (string) $stderr);
    }
}
