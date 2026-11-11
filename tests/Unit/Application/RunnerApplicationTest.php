<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Tests\Unit\Application;
use PHPUnit\Framework\TestCase;
use voku\AgentLoopRunner\Application\ExitCode;
use voku\AgentLoopRunner\Application\RunnerApplication;
final class RunnerApplicationTest extends TestCase
{
    public function testUsageIsMachineDistinguishable():void{self::assertSame(ExitCode::USAGE,(new RunnerApplication(__DIR__))->run([]));}
    public function testMissingTaskIsUsage():void{self::assertSame(ExitCode::USAGE,(new RunnerApplication(__DIR__))->run(['status']));}
    public function testWatchNeedsTask(): void
    {
        self::assertSame(ExitCode::USAGE, (new RunnerApplication(__DIR__))->run(['watch']));
    }

    public function testWatchRejectsInvalidPollingLimitsBeforeTouchingOwner(): void
    {
        self::assertSame(
            ExitCode::USAGE,
            (new RunnerApplication(__DIR__))->run(['watch', 'TASK-1', '--interval-seconds=0']),
        );
    }

    public function testRunRejectsUnknownOptionsInsteadOfIgnoringThem(): void
    {
        self::assertSame(
            ExitCode::USAGE,
            (new RunnerApplication(__DIR__))->run(['run', 'TASK-1', '--not-an-option']),
        );
    }

    public function testResumeRejectsDuplicateWatchSwitch(): void
    {
        self::assertSame(
            ExitCode::USAGE,
            (new RunnerApplication(__DIR__))->run(['resume', 'TASK-1', '--watch', '--watch']),
        );
    }
}
