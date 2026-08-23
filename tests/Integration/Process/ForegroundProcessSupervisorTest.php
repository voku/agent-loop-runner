<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Tests\Integration\Process;
use PHPUnit\Framework\TestCase;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\ProcessRequest;
final class ForegroundProcessSupervisorTest extends TestCase
{
 private ForegroundProcessSupervisor $supervisor;protected function setUp():void{$this->supervisor=new ForegroundProcessSupervisor();}
 public function testReturnsExactExitAfterStatusPollingAndSeparatesUnicodeStreams():void{$r=$this->executeRequest(['php','-r','fwrite(STDOUT,"héllo");fwrite(STDERR,"échec");exit(37);']);self::assertSame(37,$r->exitCode);self::assertSame('héllo',$r->stdout);self::assertSame('échec',$r->stderr);}
 public function testArgumentsRemainDataWithoutShellInterpretation():void{$hostile='$(touch /tmp/runner-pwned); `id`; a b " c';@unlink('/tmp/runner-pwned');$r=$this->executeRequest(['php','-r','echo $argv[1];',$hostile]);self::assertSame($hostile,$r->stdout);self::assertFileDoesNotExist('/tmp/runner-pwned');}
 public function testCapturesLargeOutput():void{$r=$this->executeRequest(['php','-r','echo str_repeat("x", 1500000);']);self::assertSame(1_500_000,strlen($r->stdout));self::assertSame(0,$r->exitCode);}
 public function testTimeoutTerminatesDescendantProcessGroup():void
 {
  if(PHP_OS_FAMILY==='Windows'||!function_exists('pcntl_fork'))self::markTestSkipped('POSIX process groups with PCNTL required.');$marker=sys_get_temp_dir().'/runner-descendant-'.bin2hex(random_bytes(4));
  $script='$p=$argv[1];$pid=pcntl_fork();if($pid===0){sleep(2);file_put_contents($p,"escaped");exit;}sleep(10);';$r=$this->executeRequest(['php','-r',$script,$marker],1);self::assertTrue($r->timedOut);self::assertSame(124,$r->exitCode);sleep(2);self::assertFileDoesNotExist($marker);
 }
 /** @param non-empty-list<non-empty-string> $argv */private function executeRequest(array$argv,int$timeout=5):\voku\AgentLoopRunner\Process\ProcessResult{return$this->supervisor->run(new ProcessRequest($argv,__DIR__,'',['PATH'=>(string)getenv('PATH')],$timeout));}
}
