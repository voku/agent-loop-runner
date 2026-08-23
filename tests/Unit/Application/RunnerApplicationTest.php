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
}
