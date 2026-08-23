<?php

declare(strict_types=1);
namespace voku\AgentLoopRunner\Tests\Integration\Execution;

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
use voku\AgentLoopRunner\Execution\CoordinatorHook;
use voku\AgentLoopRunner\Execution\ExecutionCoordinator;
use voku\AgentLoopRunner\Execution\ExecutionGatewayPort;
use voku\AgentLoopRunner\Execution\NullCoordinatorHook;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\HostAdapter;
use voku\AgentLoopRunner\Host\HostAvailability;
use voku\AgentLoopRunner\Host\HostExecutionRequest;
use voku\AgentLoopRunner\Host\HostExecutionResult;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;
use voku\AgentLoopRunner\Process\ProcessResult;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RunExecutionLock;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

final class ExecutionCoordinatorRestartTest extends TestCase
{
    private string $root;
    private string $base;
    private RuntimeJournal $journal;
    private RunWorkspaceManager $workspaces;
    protected function setUp(): void
    {
        $this->root=sys_get_temp_dir().'/runner-coordinate-'.bin2hex(random_bytes(5)); mkdir($this->root); $this->git(['init','-q']); $this->git(['config','user.email','x@y.test']); $this->git(['config','user.name','X']); file_put_contents($this->root.'/file.txt','base'); $this->git(['add','.']); $this->git(['commit','-qm','base']); $this->base=trim($this->git(['rev-parse','HEAD']));
        $layout=new RunnerLayout($this->root); $command=new GitCommand(new ForegroundProcessSupervisor(),['PATH'=>(string)getenv('PATH')]); $this->journal=new RuntimeJournal($layout); $this->workspaces=new RunWorkspaceManager($layout,new GitWorktreeService($command),new WorkspaceCandidateHasher($command));
    }
    protected function tearDown(): void { exec('rm -rf '.escapeshellarg($this->root)); }

    public function testCrashAfterAuthorityAcceptedDoesNotExecuteHostTwice(): void
    {
        $gateway=new FakeGateway($this->root,$this->base); $host=new CountingHost();
        $crashing=$this->coordinator($gateway,$host,new ThrowAtBoundary('after_submission_accepted'));
        try { $crashing->run('TASK'); self::fail('Expected injected crash.'); } catch (InjectedCrash) {}
        self::assertSame(1,$host->executions); self::assertTrue($gateway->projection('TASK')->complete());
        $complete=$this->coordinator($gateway,$host,new NullCoordinatorHook())->resume('TASK');
        self::assertTrue($complete->complete()); self::assertSame(1,$host->executions);
        self::assertSame(AttemptStatus::ResultPersisted,$this->journal->load('TASK')?->status);
    }

    public function testCrashAfterResultPersistenceResubmitsExactResultWithoutHostExecution(): void
    {
        $gateway=new FakeGateway($this->root,$this->base); $host=new CountingHost();
        try { $this->coordinator($gateway,$host,new ThrowAtBoundary('after_result_persisted'))->run('TASK'); self::fail('Expected injected crash.'); } catch (InjectedCrash) {}
        $submission=$this->journal->load('TASK')?->submissionId;
        $this->coordinator($gateway,$host,new NullCoordinatorHook())->resume('TASK');
        self::assertSame(1,$host->executions); self::assertSame([$submission],$gateway->submissions);
    }

    public function testCrashBeforeProcessReusesStableSubmissionAndRunsOnceOnResume(): void
    {
        $gateway=new FakeGateway($this->root,$this->base); $host=new CountingHost();
        try { $this->coordinator($gateway,$host,new ThrowAtBoundary('before_process_start'))->run('TASK'); self::fail('Expected injected crash.'); } catch (InjectedCrash) {}
        $submission=$this->journal->load('TASK')?->submissionId;
        $this->coordinator($gateway,$host,new NullCoordinatorHook())->resume('TASK');
        self::assertSame(1,$host->executions); self::assertSame([$submission],$gateway->submissions);
    }

    public function testConcurrentRunnerForSameTaskFailsBeforeSecondHostExecution(): void
    {
        $lock=RunExecutionLock::acquire(new RunnerLayout($this->root),'TASK');
        $gateway=new FakeGateway($this->root,$this->base); $host=new CountingHost();
        try {
            $this->coordinator($gateway,$host,new NullCoordinatorHook())->run('TASK');
            self::fail('Expected same-task execution lock rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('STALE_RUN: another Runner process', $exception->getMessage());
            self::assertSame(0,$host->executions);
        } finally {
            $lock->release();
        }
    }

    private function coordinator(FakeGateway $gateway, CountingHost $host, CoordinatorHook $hook): ExecutionCoordinator
    { return new ExecutionCoordinator($gateway,$this->journal,$this->workspaces,new CompletionEnvelopeParser(),RunnerConfig::defaults(),['codex'=>$host],new ForegroundProcessSupervisor(),new DiagnosticLogStore(new RunnerLayout($this->root)),$hook); }
    /** @param list<string> $args */
    private function git(array $args): string { $p=proc_open(['git','-C',$this->root,...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes); self::assertIsResource($p); $o=stream_get_contents($pipes[1]);$e=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),(string)$e);return(string)$o; }
}

final class FakeGateway implements ExecutionGatewayPort
{
    public bool $complete=false;
    /** @var list<string> */ public array $submissions=[];
    public function __construct(private readonly string $root,private readonly string $base) {}
    public function projection(string $taskId): ExecutionProjection { return new ExecutionProjection($taskId,'RUN',1,ExecutionProfileName::SURGICAL,'sha256:'.str_repeat('a',64),$this->complete?null:'builder',1,null,[],$this->base); }
    public function prepareStage(string $taskId,string $stageId): StageExecutionBundle { return new StageExecutionBundle($taskId,'RUN',1,'sha256:'.str_repeat('a',64),$stageId,1,ExecutionStageKind::AGENT,'builder',true,$this->root,$this->base,$this->base,['path'=>'contract','sha256'=>'sha256:'.str_repeat('b',64)],null,['src'],['test'],null,[StageOutcome::PASS,StageOutcome::FAILED],'AGENT_LOOP_STAGE_RESULT ',"do work\n"); }
    public function submitStageResult(StageResult $result): ExecutionProjection { $this->submissions[]=$result->submissionId;$this->complete=true;return $this->projection($result->taskId); }
    public function runDeterministicStage(string $taskId,string $stageId): ExecutionProjection { throw new RuntimeException('not expected'); }
}
final class CountingHost implements HostAdapter
{
    public int$executions=0;
    public function id(): string{return'codex';}
    public function probe(ProcessSupervisor $processSupervisor,string $workingDirectory,array $environment):HostAvailability{return new HostAvailability('codex','fake','1',null);}
    public function execute(HostExecutionRequest $request,ProcessSupervisor $processSupervisor):HostExecutionResult{$this->executions++;return new HostExecutionResult('codex',new ProcessResult(0,"AGENT_LOOP_STAGE_RESULT {\"outcome\":\"pass\",\"summary\":\"done\",\"artifact_references\":[],\"validation_references\":[]}\n",'',false,'2026-01-01T00:00:00+00:00','2026-01-01T00:00:01+00:00'));}
}
final readonly class ThrowAtBoundary implements CoordinatorHook { public function __construct(private string $boundary){} public function reached(string $boundary):void{if($boundary===$this->boundary)throw new InjectedCrash();} }
final class InjectedCrash extends RuntimeException {}
