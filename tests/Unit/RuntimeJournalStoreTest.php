<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\RuntimeJournal;
use voku\AgentLoopRunner\Runtime\RuntimeJournalStore;
use voku\AgentLoopRunner\Runtime\RuntimeStatus;

final class RuntimeJournalStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-runner-runtime-' . bin2hex(random_bytes(6));
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
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testPersistsAndReloadsExactStageResultWithoutEnvironmentSecrets(): void
    {
        $result = new StageResult(
            'submission-1',
            'TASK-1',
            'RUN-1',
            1,
            'sha256:' . str_repeat('a', 64),
            'builder',
            1,
            StageOutcome::COMPLETED,
            'git-worktree-v1:' . str_repeat('b', 40) . ':sha256:' . str_repeat('c', 64),
            ['artifact.json'],
            ['composer test'],
            'Implemented bounded change.',
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
            candidateRevision: $result->candidateRevision,
            hostId: 'codex',
            hostVersion: 'codex 1.2.3',
            processPid: 1234,
            startedAt: '2026-08-23T20:00:00+00:00',
            finishedAt: '2026-08-23T20:00:02+00:00',
            exitCode: 0,
            timedOut: false,
            stdoutLog: '.agent-loop-runner/logs/TASK-1/stdout.log',
            stderrLog: '.agent-loop-runner/logs/TASK-1/stderr.log',
            stageResult: $result,
        );

        $layout = new RunnerLayout($this->root);
        $store = new RuntimeJournalStore($layout);
        $store->save($journal);

        $loaded = $store->load('TASK-1');
        self::assertNotNull($loaded);
        self::assertSame($journal->toArray(), $loaded->toArray());

        $raw = file_get_contents($layout->runtime('TASK-1'));
        self::assertIsString($raw);
        self::assertStringNotContainsString('OPENAI_API_KEY', $raw);
        self::assertStringNotContainsString('super-secret-value', $raw);
    }

    public function testMalformedJournalFailsClosed(): void
    {
        $layout = new RunnerLayout($this->root);
        $path = $layout->runtime('TASK-1');
        self::assertTrue(mkdir(dirname($path), 0700, true));
        file_put_contents($path, '{not-json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');

        (new RuntimeJournalStore($layout))->load('TASK-1');
    }
}
