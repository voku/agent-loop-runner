<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use voku\AgentLoopRunner\RunnerLayout;
use voku\AgentLoopRunner\Runtime\DiagnosticLogStore;

final class DiagnosticLogStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-runner-logs-' . bin2hex(random_bytes(6));
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

    public function testPersistedLogsAreHashBoundAndReplayable(): void
    {
        $store = new DiagnosticLogStore(new RunnerLayout($this->root));
        $references = $store->persist('TASK-1', 'RUN-1', 'builder', 1, "stdout\n", "stderr\n");

        self::assertStringContainsString('#sha256:', $references['stdout']);
        self::assertSame("stdout\n", $store->read($references['stdout']));
        self::assertSame("stderr\n", $store->read($references['stderr']));
    }

    public function testTamperedLogFailsClosed(): void
    {
        $store = new DiagnosticLogStore(new RunnerLayout($this->root));
        $reference = $store->persist('TASK-1', 'RUN-1', 'builder', 1, 'original', '')['stdout'];
        $relative = strstr($reference, '#sha256:', true);
        self::assertIsString($relative);
        self::assertNotSame('', $relative);
        self::assertIsInt(file_put_contents($this->root . '/' . $relative, 'tampered'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale or corrupt');
        $store->read($reference);
    }
}
