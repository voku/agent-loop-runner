<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Process;

use RuntimeException;
use SplFileObject;

final readonly class ProcessIdentity
{
    /** @return non-empty-string|null */
    public static function fingerprint(int $pid): ?string
    {
        if ($pid < 1 || PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        try {
            $file = new SplFileObject('/proc/' . $pid . '/stat', 'rb');
            $stat = $file->fgets();
        } catch (RuntimeException) {
            return null;
        }
        if ($stat === '') {
            return null;
        }
        $close = strrpos($stat, ')');
        if ($close === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));
        if ($fields === false || !isset($fields[19]) || $fields[19] === '') {
            return null;
        }

        return 'sha256:' . hash('sha256', $pid . "\0" . $fields[19]);
    }
}
