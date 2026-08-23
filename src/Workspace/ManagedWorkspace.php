<?php

declare(strict_types=1);
namespace voku\AgentLoopRunner\Workspace;
final readonly class ManagedWorkspace
{
    public function __construct(public WorkspaceLease $lease, public string $initialCandidateRevision, public ?WorkspaceMutationLock $mutationLock) {}
}
