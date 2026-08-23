<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use JsonException;
use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;

final readonly class RuntimeJournalStore
{
    public function __construct(private RunnerLayout $layout)
    {
    }

    public function load(string $taskId): ?RuntimeJournal
    {
        $path = $this->layout->runtime($taskId);
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read Runner runtime journal: ' . $path);
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Runner runtime journal is invalid JSON: ' . $path, 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Runner runtime journal must be a JSON object: ' . $path);
        }

        return RuntimeJournal::fromArray($data);
    }

    public function save(RuntimeJournal $journal): void
    {
        $path = $this->layout->runtime($journal->taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Runner runtime directory: ' . $directory);
        }

        try {
            $json = json_encode($journal->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Runner runtime journal.', 0, $exception);
        }

        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to create Runner runtime temporary file: ' . $temporary);
        }

        try {
            if (fwrite($handle, $json) !== strlen($json)) {
                throw new RuntimeException('Unable to write complete Runner runtime journal: ' . $temporary);
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush Runner runtime journal: ' . $temporary);
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Unable to fsync Runner runtime journal: ' . $temporary);
            }
        } finally {
            fclose($handle);
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish Runner runtime journal atomically: ' . $path);
        }
        @chmod($path, 0600);
    }
}
