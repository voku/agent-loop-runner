<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoopRunner\Application\ExecutionCoordinator;
use voku\AgentLoopRunner\Config\RunnerConfig;
use voku\AgentLoopRunner\Execution\CompletionEnvelopeParser;
use voku\AgentLoopRunner\Execution\ExecutionOwner;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Host\HostAdapterFactory;
use voku\AgentLoopRunner\Process\EnvironmentProjector;
use voku\AgentLoopRunner\Process\OwnedProcessCanceller;
use voku\AgentLoopRunner\Process\ProcessRequest;
use voku\AgentLoopRunner\Process\ProcessResult;
use voku\AgentLoopRunner\Process\ProcessSupervisor;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\DiagnosticLogStore;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Runtime\RuntimeJournalStore;
use voku\AgentLoopRunner\Runtime\RuntimeReconciler;
use voku\AgentLoopRunner\Runtime\RuntimeStatus;
use voku\AgentLoopRunner\Workspace\GitWorktreeService;
use voku\AgentLoopRunner\Workspace\RunWorkspaceManager;
use voku\AgentLoopRunner\Workspace\WorkspaceCandidateHasher;

final class ExecutionCoordinatorRecoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-runner-coordinator-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testPersistedResultIsResubmittedWithoutExecutingAHost(): void
    {
        $stageResult = new StageResult(
            'submission-1',
            'TASK-1',
            'RUN-1',
            1,
            'sha256:' . str_repeat('a', 64),
            'builder',
            1,
            StageOutcome::COMPLETED,
            str_repeat('b', 40),
            [],
            [],
            'done',
        );
        $journal = new RuntimeJournal(
            taskId: 'TASK-1',
            runId: 'RUN-1',
            contractRevision: 1,
            executionPlanDigest: 'sha256:' . str_repeat('a', 64),
            stageId: 'builder',
            attempt: 1,
            submissionId: 'submission-1',
            status: RuntimeStatus::RESULT_PERSISTED,
            baseCommit: str_repeat('b', 40),
            candidateRevision: str_repeat('b', 40),
            stageResult: $stageResult,
        );

        $layout = new RunnerLayout($this->root);
        $journals = new RuntimeJournalStore($layout);
        $journals->create($journal);
        $processes = new NeverProcessSupervisor();
        $git = new GitCommand($processes, []);
        $owner = new CompletedExecutionOwner($stageResult);
        $coordinator = new ExecutionCoordinator(
            $this->root,
            $owner,
            RunnerConfig::defaults(),
            new HostAdapterFactory(),
            $processes,
            new EnvironmentProjector(),
            new RunWorkspaceManager($layout, new GitWorktreeService($git), new WorkspaceCandidateHasher($git)),
            $journals,
            new RuntimeReconciler(),
            new CompletionEnvelopeParser(),
            new DiagnosticLogStore($layout),
            new OwnedProcessCanceller(),
        );

        self::assertTrue($coordinator->run('TASK-1')->complete());
        self::assertSame(1, $owner->submissionCalls);
        self::assertSame(RuntimeStatus::RECONCILED_ACCEPTED, $journals->load('TASK-1')?->status);
    }
}

final class CompletedExecutionOwner implements ExecutionOwner
{
    public int $submissionCalls = 0;

    public function __construct(private readonly StageResult $expectedResult)
    {
    }

    public function projection(string $taskId): ExecutionProjection
    {
        return $this->completedProjection();
    }

    public function prepareStage(string $taskId, string $stageId): StageExecutionBundle
    {
        throw new RuntimeException('Host execution must not be prepared during persisted-result recovery.');
    }

    public function submitStageResult(StageResult $result): ExecutionProjection
    {
        if ($result->toArray() !== $this->expectedResult->toArray()) {
            throw new RuntimeException('Recovered StageResult changed before resubmission.');
        }
        ++$this->submissionCalls;

        return $this->completedProjection();
    }

    public function runDeterministicStage(string $taskId, string $stageId): ExecutionProjection
    {
        throw new RuntimeException('Deterministic stage must not run during persisted-result recovery.');
    }

    private function completedProjection(): ExecutionProjection
    {
        return new ExecutionProjection(
            'TASK-1',
            'RUN-1',
            1,
            ExecutionProfileName::SURGICAL,
            'sha256:' . str_repeat('a', 64),
            null,
            0,
            null,
            [],
            str_repeat('b', 40),
        );
    }
}

final class NeverProcessSupervisor implements ProcessSupervisor
{
    public function run(ProcessRequest $request): ProcessResult
    {
        throw new RuntimeException('Host process must not execute during persisted-result recovery.');
    }
}
