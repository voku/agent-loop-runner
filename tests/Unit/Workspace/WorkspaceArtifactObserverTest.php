<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Workspace;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoopRunner\Workspace\WorkspaceArtifactObserver;

/**
 * Real providers cite evidence as "path:line" or with "workspace-file:" prefix.
 *
 * @internal
 */
final class WorkspaceArtifactObserverTest extends TestCase
{
    private string $root;
    private string $baseCommit;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-runner-artifacts-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o700, true);
        file_put_contents($this->root . '/src/Example.php', "<?php\n// fixture\n");
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'runner-test@example.invalid');
        $this->git('config', 'user.name', 'Runner Test');
        $this->git('add', '.');
        $this->git('commit', '-qm', 'base');
        $this->baseCommit = $this->git('rev-parse', 'HEAD');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /**
     * @param non-empty-string ...$references
     *
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

    public function testDeletedFileEvidenceIsBoundToTheBaseBlob(): void
    {
        unlink($this->root . '/src/Example.php');

        $observation = (new WorkspaceArtifactObserver())->observe(
            $this->bundle(),
            $this->root,
            'git-tree-v1:' . $this->baseCommit . ':' . str_repeat('c', 40),
            ['deleted-file:src/Example.php'],
        )[0];

        self::assertSame('git-base-file:src/Example.php', $observation->sourceReference);
        self::assertSame('sha256:' . hash('sha256', "<?php\n// fixture\n"), $observation->sourceDigest);
    }

    public function testDeletedFileEvidenceIgnoresReplacementRefsForTheGovernedBase(): void
    {
        file_put_contents($this->root . '/src/Example.php', "<?php\n// replacement\n");
        $this->git('add', 'src/Example.php');
        $this->git('commit', '-qm', 'replacement content');
        $replacementCommit = $this->git('rev-parse', 'HEAD');
        $this->git('replace', $this->baseCommit, $replacementCommit);
        unlink($this->root . '/src/Example.php');

        $observation = (new WorkspaceArtifactObserver())->observe(
            $this->bundle(),
            $this->root,
            'git-tree-v1:' . $this->baseCommit . ':' . str_repeat('c', 40),
            ['deleted-file:src/Example.php'],
        )[0];

        self::assertSame('sha256:' . hash('sha256', "<?php\n// fixture\n"), $observation->sourceDigest);
    }

    public function testDeletedFileEvidenceRejectsAStillExistingModifiedFile(): void
    {
        file_put_contents($this->root . '/src/Example.php', "<?php\n// changed\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deleted-file evidence still exists in the candidate workspace');
        (new WorkspaceArtifactObserver())->observe(
            $this->bundle(),
            $this->root,
            'git-tree-v1:' . $this->baseCommit . ':' . str_repeat('c', 40),
            ['deleted-file:src/Example.php'],
        );
    }

    public function testDeletedFileEvidenceRejectsAPathAbsentFromTheBaseCommit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deleted-file path is not present in the governed base');
        (new WorkspaceArtifactObserver())->observe(
            $this->bundle(),
            $this->root,
            'git-tree-v1:' . $this->baseCommit . ':' . str_repeat('c', 40),
            ['deleted-file:src/New.php'],
        );
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

    /** @param string ...$arguments */
    private function git(string ...$arguments): string
    {
        exec('git -C ' . escapeshellarg($this->root) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $exit);
        if ($exit !== 0) {
            throw new RuntimeException('git ' . implode(' ', $arguments) . ' failed: ' . implode("\n", $output));
        }

        return trim(implode("\n", $output));
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
            $this->baseCommit,
            'sha256:' . str_repeat('b', 64),
            ['path' => 'contract.json', 'sha256' => 'sha256:contract'],
            null,
            [],
            [],
            null,
            [StageOutcome::COMPLETED],
            'MARKER',
            'prompt',
        );
    }
}
