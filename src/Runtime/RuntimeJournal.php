<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Runtime;

use JsonException;
use RuntimeException;
use voku\AgentLoopRunner\RunnerLayout;

final readonly class RuntimeJournal
{
    public function __construct(private RunnerLayout $layout) {}

    public function load(string $taskId): ?RuntimeAttempt
    {
        $path = $this->layout->runtime($taskId);
        if (!is_file($path)) return null;
        $json = file_get_contents($path);
        if (!is_string($json)) throw new CorruptRuntimeJournal('Unable to read runtime journal.');
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CorruptRuntimeJournal('Runtime journal is not valid JSON.', 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)) throw new CorruptRuntimeJournal('Runtime journal must be a JSON object.');
        $object = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) throw new CorruptRuntimeJournal('Runtime journal keys must be strings.');
            $object[$key] = $value;
        }
        return RuntimeAttempt::fromArray($object);
    }

    public function save(RuntimeAttempt $attempt): void
    {
        $path = $this->layout->runtime($attempt->taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create runtime journal directory.');
        }
        try {
            $json = json_encode($attempt->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode runtime journal.', 0, $exception);
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('Unable to create runtime journal temporary file.');
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Unable to durably write runtime journal.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, 0o600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to atomically replace runtime journal.');
        }
    }
}
