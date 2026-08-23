<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Diagnostics;
use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;
final readonly class DiagnosticLogStore
{
    private const int MAX_BYTES=1_048_576;
    public function __construct(private RunnerLayout$layout){}
    /** @return array{stdout_log:non-empty-string,stderr_log:non-empty-string,stdout_sha256:non-empty-string,stderr_sha256:non-empty-string,stdout_truncated:bool,stderr_truncated:bool} */
    public function persist(string$task,string$run,string$stage,int$attempt,string$stdout,string$stderr):array
    {
        $dir=$this->layout->logDirectory($task,$run,$stage,$attempt);if(!is_dir($dir)&&!mkdir($dir,0o700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create diagnostic log directory.');
        $out=$this->bounded($stdout);$err=$this->bounded($stderr);$outPath=$dir.'/stdout.log';$errPath=$dir.'/stderr.log';
        if(file_put_contents($outPath,$out['content'],LOCK_EX)===false||file_put_contents($errPath,$err['content'],LOCK_EX)===false)throw new RuntimeException('Unable to persist diagnostic logs.');chmod($outPath,0o600);chmod($errPath,0o600);
        return['stdout_log'=>$outPath,'stderr_log'=>$errPath,'stdout_sha256'=>'sha256:'.hash('sha256',$stdout),'stderr_sha256'=>'sha256:'.hash('sha256',$stderr),'stdout_truncated'=>$out['truncated'],'stderr_truncated'=>$err['truncated']];
    }
    /** @return array{content:string,truncated:bool} */private function bounded(string$value):array{if(strlen($value)<=self::MAX_BYTES)return['content'=>$value,'truncated'=>false];$half=intdiv(self::MAX_BYTES,2);return['content'=>substr($value,0,$half)."\n[... bounded diagnostic truncated ...]\n".substr($value,-$half),'truncated'=>true];}
}
