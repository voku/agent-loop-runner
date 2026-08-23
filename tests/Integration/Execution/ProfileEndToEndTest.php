<?php

declare(strict_types=1);
namespace voku\AgentLoopRunner\Tests\Integration\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Diagnostics\DiagnosticLogStore;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionCoordinator;
use voku\AgentLoopRunner\Execution\ExecutionGatewayPort;
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

final class ProfileEndToEndTest extends TestCase
{
    private string $root; private string $base;
    protected function setUp():void{$this->root=sys_get_temp_dir().'/runner-profile-'.bin2hex(random_bytes(5));mkdir($this->root);$this->git(['init','-q']);$this->git(['config','user.email','x@y.test']);$this->git(['config','user.name','X']);file_put_contents($this->root.'/a','a');$this->git(['add','.']);$this->git(['commit','-qm','base']);$this->base=trim($this->git(['rev-parse','HEAD']));}
    protected function tearDown():void{exec('rm -rf '.escapeshellarg($this->root));}
    /** @return iterable<string, array{ExecutionProfileName, list<string>}> */
    public static function profiles():iterable{
        yield'surgical'=>[ExecutionProfileName::SURGICAL,['investigator','builder','reviewer','verify']];
        yield'standard'=>[ExecutionProfileName::STANDARD,['investigator','builder','correctness-review','blindspot-review','verify']];
        yield'hardened'=>[ExecutionProfileName::HARDENED,['investigator','builder','correctness-review','architecture-review','hardening','independent-verification','blindspot-review','verify']];
    }
    /** @param list<string> $stages */
    #[DataProvider('profiles')]
    public function testProfileCompletesThroughOneCoordinator(ExecutionProfileName $profile,array $stages):void
    {
        $gateway=new ProfileGateway($this->root,$this->base,$profile,$stages);$host=new ProfileHost();$supervisor=new ForegroundProcessSupervisor();$layout=new RunnerLayout($this->root);$git=new GitCommand($supervisor,['PATH'=>(string)getenv('PATH')]);
        $coordinator=new ExecutionCoordinator($gateway,new RuntimeJournal($layout),new RunWorkspaceManager($layout,new GitWorktreeService($git),new WorkspaceCandidateHasher($git)),new CompletionEnvelopeParser(),RunnerConfig::defaults(),['codex'=>$host,'claude'=>$host],$supervisor,new DiagnosticLogStore($layout));
        $projection=$coordinator->run('TASK');self::assertTrue($projection->complete());self::assertSame(count($stages)-1,$host->executions);self::assertSame(1,$gateway->deterministicExecutions);self::assertSame($stages,$gateway->visited);
    }
    /** @param list<string> $args */private function git(array$args):string{$p=proc_open(['git','-C',$this->root,...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($p);$o=stream_get_contents($pipes[1]);$e=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),(string)$e);return(string)$o;}
}
final class ProfileGateway implements ExecutionGatewayPort
{
    private int $index=0;public int $deterministicExecutions=0;/** @var list<string> */public array$visited=[];
    /** @param list<string> $stages */public function __construct(private readonly string$root,private readonly string$base,private readonly ExecutionProfileName$profile,private readonly array$stages){}
    public function projection(string$taskId):ExecutionProjection{return new ExecutionProjection($taskId,'RUN',1,$this->profile,'sha256:'.str_repeat('d',64),$this->stages[$this->index]??null,1,null,[],$this->base);}
    public function prepareStage(string$taskId,string$stageId):StageExecutionBundle{$det=$stageId==='verify';return new StageExecutionBundle($taskId,'RUN',1,'sha256:'.str_repeat('d',64),$stageId,1,$det?ExecutionStageKind::DETERMINISTIC:ExecutionStageKind::AGENT,$det?null:$stageId,!in_array($stageId,['investigator','reviewer','correctness-review','architecture-review','independent-verification','blindspot-review'],true),$this->root,$this->base,$this->base,['path'=>'contract','sha256'=>'sha256:'.str_repeat('e',64)],null,['src'],['composer ci'],null,[StageOutcome::PASS,StageOutcome::FAILED],'AGENT_LOOP_STAGE_RESULT ','work');}
    public function submitStageResult(StageResult$result):ExecutionProjection{$this->visited[]=$result->stageId;$this->index++;return$this->projection($result->taskId);}
    public function runDeterministicStage(string$taskId,string$stageId):ExecutionProjection{$this->deterministicExecutions++;$this->visited[]=$stageId;$this->index++;return$this->projection($taskId);}
}
final class ProfileHost implements HostAdapter
{
    public int$executions=0;public function id():string{return'fake';}public function probe(ProcessSupervisor$p,string$w,array$e):HostAvailability{return new HostAvailability('fake','fake','1',null);}public function execute(HostExecutionRequest$r,ProcessSupervisor$p):HostExecutionResult{$this->executions++;return new HostExecutionResult('fake',new ProcessResult(0,'AGENT_LOOP_STAGE_RESULT {"outcome":"pass","summary":"ok","artifact_references":[],"validation_references":[]}' . "\n",'',false,'start','finish'));}
}
