<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoopRunner\Host\ClaudeHostAdapter;
use voku\AgentLoopRunner\Host\CodexHostAdapter;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Host\OpenCodeHostAdapter;
use voku\AgentLoopRunner\Process\ProcessRequest;
use voku\AgentLoopRunner\Process\ProcessResult;
use voku\AgentLoopRunner\Process\ProcessSupervisor;

final class HostAdapterContractTest extends TestCase
{
    private string $binary;

    protected function setUp(): void
    {
        $this->binary = sys_get_temp_dir() . '/agent-loop-runner-host-' . bin2hex(random_bytes(5));
        file_put_contents($this->binary, "#!/bin/sh\nexit 0\n");
        chmod($this->binary, 0o755);
    }

    protected function tearDown(): void
    {
        if (is_file($this->binary)) {
            unlink($this->binary);
        }
    }

    /**
     * @return iterable<string, array{HostAdapter, list<string>, string}>
     */
    public static function adapters(): iterable
    {
        $placeholder = '/tmp/runner-host-binary';

        yield 'codex uses exec/ephemeral with prompt on stdin' => [
            new CodexHostAdapter($placeholder),
            [$placeholder, 'exec', '--ephemeral', '-'],
            'hostile $(touch nope) `still-data`',
        ];
        yield 'claude uses print mode with prompt as argv data' => [
            new ClaudeHostAdapter($placeholder),
            [$placeholder, '-p', 'hostile $(touch nope) `still-data`'],
            '',
        ];
        yield 'opencode uses run with prompt as argv data' => [
            new OpenCodeHostAdapter($placeholder),
            [$placeholder, 'run', 'hostile $(touch nope) `still-data`'],
            '',
        ];
    }

    /** @param list<string> $expectedArgv */
    #[DataProvider('adapters')]
    public function testAdapterKeepsPromptAsProcessData(HostAdapter $template, array $expectedArgv, string $expectedStdin): void
    {
        $adapter = match ($template->id()) {
            'codex' => new CodexHostAdapter($this->binary),
            'claude' => new ClaudeHostAdapter($this->binary),
            'opencode' => new OpenCodeHostAdapter($this->binary),
            default => self::fail('Unexpected host fixture.'),
        };
        $supervisor = new RecordingProcessSupervisor();
        $prompt = 'hostile $(touch nope) `still-data`';

        $adapter->execute(new HostExecutionRequest(
            'builder',
            sys_get_temp_dir(),
            $prompt,
            ['RUNNER_TEST' => '1'],
            30,
        ), $supervisor);

        $request = $supervisor->lastRequest;
        self::assertNotNull($request);
        $expectedArgv[0] = $this->binary;
        self::assertSame($expectedArgv, $request->argv);
        self::assertSame($expectedStdin, $request->stdin);
        self::assertSame(['RUNNER_TEST' => '1'], $request->environment);
        self::assertSame(30, $request->timeoutSeconds);
    }

    public function testBinaryLookupUsesProjectedPathRelativeToRequestWorkingDirectory(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-runner-path-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($root . '/tools', 0o775, true));
        $binary = $root . '/tools/codex-local';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0o755);
        try {
            $supervisor = new RecordingProcessSupervisor();
            (new CodexHostAdapter('codex-local'))->execute(new HostExecutionRequest(
                'builder',
                $root,
                'prompt',
                ['PATH' => 'tools'],
                30,
            ), $supervisor);

            self::assertSame(realpath($binary), $supervisor->lastRequest?->argv[0] ?? null);
        } finally {
            unlink($binary);
            rmdir($root . '/tools');
            rmdir($root);
        }
    }
}

final class RecordingProcessSupervisor implements ProcessSupervisor
{
    public ?ProcessRequest $lastRequest = null;

    public function run(ProcessRequest $request): ProcessResult
    {
        $this->lastRequest = $request;

        return new ProcessResult(0, 'ok', '', false, '2026-08-23T00:00:00+00:00', '2026-08-23T00:00:01+00:00');
    }
}
