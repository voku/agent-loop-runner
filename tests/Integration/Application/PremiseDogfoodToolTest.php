<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;

final class PremiseDogfoodToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-premise-dogfood-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/bin', 0o775, true));
        self::assertTrue(mkdir($this->root . '/repo', 0o775, true));
        self::assertTrue(mkdir($this->root . '/evidence', 0o775, true));

        $fakeCodex = <<<'SH'
#!/bin/sh
if [ "${1:-}" = "--version" ]; then
    printf 'codex-dogfood 1.0\n'
    exit 0
fi
if [ "${1:-}" = "exec" ]; then
    prompt="$(cat)"
    printf '%s\n' "$prompt"
    printf 'actor observation\n' > ACTOR.txt
    exit 0
fi
exit 64
SH;
        self::assertNotFalse(file_put_contents($this->root . '/bin/codex', $fakeCodex));
        self::assertTrue(chmod($this->root . '/bin/codex', 0o755));

        $this->runGit(['init', '-q']);
        $this->runGit(['config', 'user.name', 'premise-dogfood']);
        $this->runGit(['config', 'user.email', 'premise-dogfood@example.invalid']);
        self::assertNotFalse(file_put_contents($this->root . '/repo/README.md', "fixture\n"));
        $this->runGit(['add', 'README.md']);
        $this->runGit(['commit', '-qm', 'fixture']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testActorReceivesOnlySuppliedPromptAndPersistsBoundedMetadata(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $prompt = "Work on voku/agent-loop#342 following the current repository guidance.\n";
        $promptPath = $this->root . '/prompt.txt';
        self::assertNotFalse(file_put_contents($promptPath, $prompt));

        $path = $this->root . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: '');
        $process = proc_open(
            [
                PHP_BINARY,
                $repositoryRoot . '/tools/premise-dogfood.php',
                'codex',
                $this->root . '/repo',
                $promptPath,
                $this->root . '/evidence',
            ],
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
        $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $result */
        $result = $decoded;
        self::assertSame('PROCESS_COMPLETED', $result['status'] ?? null);
        self::assertSame('codex', $result['host'] ?? null);
        self::assertSame('codex-dogfood 1.0', $result['version'] ?? null);
        self::assertFalse($result['working_tree_clean'] ?? true);
        self::assertSame(hash('sha256', $prompt), $result['prompt_sha256'] ?? null);
        self::assertArrayNotHasKey('stdout', $result);
        self::assertArrayNotHasKey('stderr', $result);

        self::assertSame($prompt, file_get_contents($this->root . '/evidence/stdout.txt'));
        self::assertSame('', file_get_contents($this->root . '/evidence/stderr.txt'));
        self::assertFileExists($this->root . '/evidence/result.json');
        self::assertFileExists($this->root . '/repo/ACTOR.txt');
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
}
