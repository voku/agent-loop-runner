<?php

declare(strict_types=1);
namespace voku\AgentLoopRunner\Workspace;

final class WorkspaceMutationLock
{
    /** @param resource $handle */
    public function __construct(private $handle) {}
    public function release(): void
    {
        if (is_resource($this->handle)) { flock($this->handle, LOCK_UN); fclose($this->handle); }
    }
    public function __destruct() { $this->release(); }
}
