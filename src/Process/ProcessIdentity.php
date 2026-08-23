<?php
declare(strict_types=1);
namespace voku\AgentLoopRunner\Process;
final readonly class ProcessIdentity
{
 /** @return non-empty-string|null */public static function fingerprint(int$pid):?string{$stat=@file_get_contents('/proc/'.$pid.'/stat');if(!is_string($stat)||$stat==='')return null;$close=strrpos($stat,')');if($close===false)return null;$fields=preg_split('/\s+/',trim(substr($stat,$close+1)));if($fields===false||!isset($fields[19])||$fields[19]==='')return null;return'sha256:'.hash('sha256',$pid."\0".$fields[19]);}
}
