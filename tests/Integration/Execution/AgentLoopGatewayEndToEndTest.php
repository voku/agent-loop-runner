<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Tests\Integration\Execution;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Diagnostics\DiagnosticLogStore;
use voku\AgentLoopRunner\Execution\AgentLoopExecutionGateway;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionCoordinator;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\HostAvailability;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Host\HostExecutionResult;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\ProcessResult;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;
final class AgentLoopGatewayEndToEndTest extends TestCase
{
 private string$root;private string$base;
 protected function setUp():void{$this->root=sys_get_temp_dir().'/runner-real-gateway-'.bin2hex(random_bytes(5));mkdir($this->root.'/.agent-loop/learning',0o775,true);mkdir($this->root.'/src',0o775,true);file_put_contents($this->root.'/src/Foo.php','<?php final class Foo {}');$this->git(['init','-q']);$this->git(['config','user.email','x@y.test']);$this->git(['config','user.name','X']);$this->git(['add','.']);$this->git(['commit','-qm','base']);$this->base=trim($this->git(['rev-parse','HEAD']));}
 protected function tearDown():void{exec('rm -rf '.escapeshellarg($this->root));}
 /** @return iterable<string, array{string, int}> */
 public static function profiles(): iterable { yield 'surgical'=>['surgical',3]; yield 'standard'=>['standard',4]; yield 'hardened'=>['hardened',7]; }
 #[DataProvider('profiles')]
 public function testProfilesUsePublicTypedGatewayEndToEnd(string $profile, int $agentStages):void
 {
  ob_start();self::assertSame(0,(new WorkflowPlanCommand($this->root))->run(['TASK-1','--by','owner','--file','src/Foo.php','--goal','Prove runner orchestration.','--validation','composer ci','--base-commit',$this->base]));self::assertSame(0,(new WorkflowApproveCommand($this->root))->run(['TASK-1','--by','owner']));self::assertSame(0,(new WorkflowExecutionProfileCommand($this->root))->run(['TASK-1','--profile',$profile,'--by','owner']));ob_end_clean();
  ob_start();$exit=(new HostFrontDoorCommand($this->root,function(array$argv):int{$dir=$this->root.'/.agent-loop/recall/TASK-1';mkdir($dir,0o775,true);file_put_contents($dir.'/meta.json',json_encode(['schema_version'=>'1.0','task_id'=>'TASK-1','compilation_id'=>'fixture','selected_guidance'=>[],'selected_constraints'=>[],'output_hashes'=>[]],JSON_THROW_ON_ERROR));file_put_contents($dir.'/system.md',"# Recall\nStay governed.\n");return 0;}))->run('enter',['TASK-1','--format=json']);ob_end_clean();self::assertSame(0,$exit);
  $layout=new RunnerLayout($this->root);$supervisor=new ForegroundProcessSupervisor();$git=new GitCommand($supervisor,['PATH'=>(string)getenv('PATH')]);$host=new OutcomeHost();$coordinator=new ExecutionCoordinator(new AgentLoopExecutionGateway(new ExecutionGateway($this->root)),new RuntimeJournal($layout),new RunWorkspaceManager($layout,new GitWorktreeService($git),new WorkspaceCandidateHasher($git)),new CompletionEnvelopeParser(),RunnerConfig::defaults(),['codex'=>$host,'claude'=>$host],$supervisor,new DiagnosticLogStore($layout));
  $projection=$coordinator->run('TASK-1');self::assertTrue($projection->complete());self::assertSame($agentStages,$host->executions);self::assertSame($profile,$projection->profile->value);
 }
 /** @param list<string>$args */private function git(array$args):string{$p=proc_open(['git','-C',$this->root,...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($p);$o=stream_get_contents($pipes[1]);$e=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),(string)$e);return(string)$o;}
}
final class OutcomeHost implements HostAdapter
{
 public int$executions=0;public function id():string{return'fake';}public function probe(ProcessSupervisor$p,string$w,array$e):HostAvailability{return new HostAvailability('fake','fake','1',null);}public function execute(HostExecutionRequest$r,ProcessSupervisor$p):HostExecutionResult{$this->executions++;$outcome=in_array($r->roleId,['investigator','builder','hardening'],true)?'completed':'pass';$stdout='AGENT_LOOP_STAGE_RESULT '.json_encode(['outcome'=>$outcome,'summary'=>'fixture '.$r->roleId,'artifact_references'=>[],'validation_references'=>[]],JSON_THROW_ON_ERROR)."\n";return new HostExecutionResult('fake',new ProcessResult(0,$stdout,'',false,'start','finish'));}
}
