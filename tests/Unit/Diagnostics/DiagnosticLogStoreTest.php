<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Tests\Unit\Diagnostics;
use PHPUnit\Framework\TestCase;
use voku\AgentLoopRunner\Diagnostics\DiagnosticLogStore;
use voku\AgentLoopRunner\RunnerLayout;
final class DiagnosticLogStoreTest extends TestCase
{
 public function testSeparatesBoundsAndHashesEvidence():void{$root=sys_get_temp_dir().'/runner-log-'.bin2hex(random_bytes(4));mkdir($root);$result=(new DiagnosticLogStore(new RunnerLayout($root)))->persist('T','R','S',1,str_repeat('o',1_100_000),'err');self::assertTrue($result['stdout_truncated']);self::assertFalse($result['stderr_truncated']);self::assertSame('err',file_get_contents($result['stderr_log']));self::assertSame('sha256:'.hash('sha256',str_repeat('o',1_100_000)),$result['stdout_sha256']);exec('rm -rf '.escapeshellarg($root));}
}
