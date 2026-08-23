<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Runtime\RuntimeJournalStore;
use voku\AgentLoopRunner\Runtime\RuntimeStatus;

final class RuntimeJournalCasTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-runner-cas-' . bin2hex(random_bytes(6));
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

    public function testTransitionRejectsAStaleWriter(): void
    {
        $store = new RuntimeJournalStore(new RunnerLayout($this->root));
        $prepared = self::journal('submission-1', RuntimeStatus::PREPARED);
        $store->create($prepared);

        $starting = $prepared->withProcessStarting('codex', 'codex 1.0');
        $store->transition($prepared, $starting);
        self::assertSame(RuntimeStatus::PROCESS_STARTED, $store->load('TASK-1')?->status);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_RUN');
        $store->transition($prepared, $prepared->withStatus(RuntimeStatus::FAILED));
    }

    public function testCompetingAttemptCannotReplaceExistingJournal(): void
    {
        $store = new RuntimeJournalStore(new RunnerLayout($this->root));
        $store->create(self::journal('submission-1', RuntimeStatus::PREPARED));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $store->create(self::journal('submission-2', RuntimeStatus::PREPARED));
    }

    private static function journal(string $submissionId, RuntimeStatus $status): RuntimeJournal
    {
        return new RuntimeJournal(
            taskId: 'TASK-1',
            runId: 'RUN-1',
            contractRevision: 1,
            executionPlanDigest: 'sha256:' . str_repeat('a', 64),
            stageId: 'builder',
            attempt: 1,
            submissionId: $submissionId,
            status: $status,
            baseCommit: str_repeat('b', 40),
            candidateRevision: str_repeat('b', 40),
        );
    }
}
