<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Runtime;
use voku\AgentLoopRunner\Process\ProcessObserver;
use voku\AgentLoopRunner\Process\ProcessIdentity;
final readonly class JournalProcessObserver implements ProcessObserver
{
 public function __construct(private RuntimeJournal$journal,private RuntimeAttempt$attempt){}
 /** @param non-empty-string $startedAt */ public function started(int$pid,string$startedAt):void{$this->journal->save(new RuntimeAttempt($this->attempt->taskId,$this->attempt->runId,$this->attempt->contractRevision,$this->attempt->executionPlanDigest,$this->attempt->stageId,$this->attempt->attempt,$this->attempt->hostId,$this->attempt->workspaceIdentity,$this->attempt->submissionId,AttemptStatus::ProcessStarted,null,null,array_filter(['pid'=>$pid,'started_at'=>$startedAt,'process_fingerprint'=>ProcessIdentity::fingerprint($pid)],static fn(mixed $value):bool=>$value!==null)));}
}
