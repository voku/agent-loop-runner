<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Workspace;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoopRunner\Workspace\WorkspaceArtifactObserver;

/**
 * Real providers cite evidence as "path:line" or with "workspace-file:" prefix.
 *
 * @internal
 */
final class WorkspaceArtifactObserverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-runner-artifacts-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o700, true);
        file_put_contents($this->root . '/src/Example.php', "<?php\n// fixture\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /**
     * @return list<string>
     */
    private function observeReferences(string ...$references): array
    {
        $observations = (new WorkspaceArtifactObserver())->observe(
            $this->bundle(),
            $this->root,
            'sha256:' . str_repeat('b', 64),
            array_values($references),
        );

        return array_map(static fn ($o): string => $o->sourceReference, $observations);
    }

    public function testAcceptsAPlainPath(): void
    {
        self::assertSame(
            ['workspace-file:src/Example.php'],
            $this->observeReferences('src/Example.php'),
        );
    }

    public function testAcceptsWorkspaceFilePrefix(): void
    {
        self::assertSame(
            ['workspace-file:src/Example.php'],
            $this->observeReferences('workspace-file:src/Example.php'),
        );
    }

    public function testAcceptsALineCitationAndRecordsTheFileItself(): void
    {
        self::assertSame(
            ['workspace-file:src/Example.php'],
            $this->observeReferences('src/Example.php:70'),
        );
    }

    public function testAcceptsWorkspaceFilePrefixWithLineCitation(): void
    {
        self::assertSame(
            ['workspace-file:src/Example.php'],
            $this->observeReferences('workspace-file:src/Example.php:70'),
        );
    }

    public function testAcceptsLineAndColumnAndRangeCitations(): void
    {
        self::assertSame(
            ['workspace-file:src/Example.php'],
            $this->observeReferences('src/Example.php:26:4'),
        );
        self::assertSame(
            ['workspace-file:src/Example.php'],
            $this->observeReferences('src/Example.php:10-20'),
        );
    }

    public function testStillRejectsAGenuinelyMissingArtifact(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requested artifact does not exist');
        $this->observeReferences('src/Missing.php:12');
    }

    public function testStillRejectsPathTraversalAndAbsolutePaths(): void
    {
        $this->expectException(RuntimeException::class);
        $this->observeReferences('../outside.php:1');
    }

    public function testDoesNotStripAColonThatIsPartOfTheRealFilename(): void
    {
        // The literal path always wins, so a file that genuinely contains a
        // colon is never mistaken for a citation.
        file_put_contents($this->root . '/src/odd:12', "fixture\n");

        self::assertSame(
            ['workspace-file:src/odd:12'],
            $this->observeReferences('src/odd:12'),
        );
    }

    private function bundle(): StageExecutionBundle
    {
        return new StageExecutionBundle(
            'TASK-1',
            'run-1',
            1,
            'sha256:' . str_repeat('a', 64),
            'investigate',
            1,
            ExecutionStageKind::AGENT,
            null,
            false,
            $this->root,
            null,
            'sha256:' . str_repeat('b', 64),
            ['path' => 'contract.json', 'sha256' => 'sha256:contract'],
            null,
            [],
            [],
            null,
            ['completed'],
            'MARKER',
            'prompt',
        );
    }
}
