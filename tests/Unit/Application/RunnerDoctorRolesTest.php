<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;

final class RunnerDoctorRolesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runner-doctor-roles-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop-runner', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root . '/fake-claude', $this->root . '/.agent-loop-runner/config.json'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->root . '/.agent-loop-runner');
        rmdir($this->root);
    }

    private function autoloadFile(): string
    {
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($loader);

        return dirname($loader, 2) . '/autoload.php';
    }

    public function testRolesDistinguishAvailableAndMissingHosts(): void
    {
        $fake = $this->root . '/fake-claude';
        file_put_contents($fake, "#!/bin/sh\necho 'fake 1.0'\n");
        chmod($fake, 0755);
        file_put_contents($this->root . '/.agent-loop-runner/config.json', json_encode([
            'schema_version' => 1,
            'hosts' => [
                'claude' => ['binary' => $fake],
                'codex' => ['binary' => $this->root . '/missing-codex'],
            ],
            'roles' => ['builder' => 'codex', 'reviewer' => 'claude'],
        ], JSON_THROW_ON_ERROR));

        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                'require $argv[1]; spl_autoload_register(static function (string $c) use ($argv): void {'
                . ' $p = "voku\\\\AgentLoopRunner\\\\";'
                . ' if (str_starts_with($c, $p) && is_file($f = $argv[2] . "/" . str_replace("\\\\", "/", substr($c, strlen($p))) . ".php")) { require $f; }'
                . ' }); exit((new voku\AgentLoopRunner\Application\RunnerApplication(getcwd()))->run(["doctor"]));',
                $this->autoloadFile(),
                dirname(__DIR__, 3) . '/src',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $exit = proc_close($process);
        self::assertJson($stdout, 'stdout: ' . $stdout . ' stderr: ' . $stderr);

        $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $exit);
        self::assertSame('unavailable', $data['host_codex']);
        self::assertSame('codex: unavailable', $data['roles']['builder']);
        self::assertSame('claude: available', $data['roles']['reviewer']);
        self::assertArrayHasKey('php', $data);
        self::assertArrayHasKey('execution_gateway', $data);
    }
}
