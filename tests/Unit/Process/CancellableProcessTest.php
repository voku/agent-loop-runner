<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use voku\AgentLoopRunner\Process\CancellableProcess;
use voku\AgentLoopRunner\Process\ProcessIdentity;

final class CancellableProcessTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
    }

    public function testLiveMatchingGroupLeaderIsEligible(): void
    {
        [$pid, $fingerprint] = $this->liveGroupLeader();

        self::assertNull(CancellableProcess::problem(['pid' => $pid, 'process_fingerprint' => $fingerprint]));
    }

    public function testLiveMatchingProcessThatIsNotAGroupLeaderIsNotEligible(): void
    {
        $process = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;
        $pid = proc_get_status($process)['pid'];
        $fingerprint = ProcessIdentity::fingerprint($pid);
        self::assertNotNull($fingerprint);

        self::assertSame(
            'owned process is not in an isolated process group.',
            CancellableProcess::problem(['pid' => $pid, 'process_fingerprint' => $fingerprint]),
        );
    }

    public function testReusedPidWithADifferentFingerprintIsStale(): void
    {
        [$pid] = $this->liveGroupLeader();

        self::assertSame(
            'owned process identity is stale.',
            CancellableProcess::problem(['pid' => $pid, 'process_fingerprint' => 'sha256:the-previous-owner']),
        );
    }

    public function testPidThatNoLongerExistsIsStale(): void
    {
        $process = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [], $pipes);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        $fingerprint = ProcessIdentity::fingerprint($pid);
        self::assertNotNull($fingerprint);
        self::assertTrue(proc_terminate($process, SIGKILL));
        proc_close($process);
        self::assertFalse(posix_kill($pid, 0), 'fixture pid must be gone');

        self::assertSame(
            'owned process identity is stale.',
            CancellableProcess::problem(['pid' => $pid, 'process_fingerprint' => $fingerprint]),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableEvidence(): iterable
    {
        yield 'empty' => [[]];
        yield 'pid only' => [['pid' => 1]];
        yield 'fingerprint only' => [['process_fingerprint' => 'sha256:x']];
        yield 'pid is not an int' => [['pid' => '123', 'process_fingerprint' => 'sha256:x']];
        yield 'fingerprint is not a string' => [['pid' => 123, 'process_fingerprint' => 7]];
        yield 'impossible pid cannot be inspected' => [['pid' => 0, 'process_fingerprint' => 'sha256:x']];
        yield 'negative pid cannot be inspected' => [['pid' => -5, 'process_fingerprint' => 'sha256:x']];
    }

    /** @param array<string, mixed> $process */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableEvidence')]
    public function testMissingOrUninspectableIdentityIsNotEligible(array $process): void
    {
        self::assertSame('owned process identity is stale.', CancellableProcess::problem($process));
    }

    /** @return array{int, non-empty-string} */
    private function liveGroupLeader(): array
    {
        if (!is_executable('/usr/bin/setsid')) {
            self::markTestSkipped('setsid is required to start a process-group leader.');
        }
        $process = proc_open(['/usr/bin/setsid', 'sleep', '30'], [], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;
        $pid = proc_get_status($process)['pid'];
        for ($i = 0; $i < 50 && posix_getpgid($pid) !== $pid; ++$i) {
            usleep(100_000);
        }
        self::assertSame($pid, posix_getpgid($pid), 'fixture must lead its own process group');
        $fingerprint = ProcessIdentity::fingerprint($pid);
        self::assertNotNull($fingerprint);

        return [$pid, $fingerprint];
    }
}
