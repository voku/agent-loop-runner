<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;

final readonly class DiagnosticLogStore
{
    public function __construct(private RunnerLayout $layout)
    {
    }

    /** @return array{stdout: non-empty-string, stderr: non-empty-string} */
    public function persist(
        string $taskId,
        string $runId,
        string $stageId,
        int $attempt,
        string $stdout,
        string $stderr,
    ): array {
        $directory = $this->layout->logDirectory($taskId, $runId, $stageId, $attempt);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Runner diagnostic log directory: ' . $directory);
        }

        return [
            'stdout' => $this->write($directory . '/stdout.log', $stdout),
            'stderr' => $this->write($directory . '/stderr.log', $stderr),
        ];
    }

    public function read(string $reference): string
    {
        $separator = strrpos($reference, '#sha256:');
        if ($separator === false) {
            throw new RuntimeException('Runner log reference is missing its sha256 binding.');
        }
        $relative = substr($reference, 0, $separator);
        $digest = substr($reference, $separator + 1);
        if (
            !str_starts_with($relative, '.agent-loop-runner/logs/')
            || str_contains($relative, "\0")
            || str_contains('/' . $relative . '/', '/../')
            || preg_match('/^sha256:[a-f0-9]{64}$/', $digest) !== 1
        ) {
            throw new RuntimeException('Runner log reference is invalid.');
        }

        $path = $this->layout->projectRoot() . '/' . $relative;
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Runner log reference cannot be read: ' . $relative);
        }
        if (!hash_equals($digest, 'sha256:' . hash('sha256', $contents))) {
            throw new RuntimeException('Runner log reference is stale or corrupt: ' . $relative);
        }

        return $contents;
    }

    /** @return non-empty-string */
    private function write(string $path, string $contents): string
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException('Unable to persist complete Runner diagnostic log: ' . $path);
        }
        if (!chmod($temporary, 0600)) {
            unlink($temporary);
            throw new RuntimeException('Unable to protect Runner diagnostic log: ' . $path);
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('Unable to publish Runner diagnostic log atomically: ' . $path);
        }

        $root = $this->layout->projectRoot() . '/';
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with($normalized, $root)) {
            throw new RuntimeException('Runner diagnostic log escaped the project root.');
        }
        $relative = substr($normalized, strlen($root));

        return $relative . '#sha256:' . hash('sha256', $contents);
    }
}
