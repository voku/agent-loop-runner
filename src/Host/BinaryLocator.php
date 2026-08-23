<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class BinaryLocator
{
    public function locate(string $binary): ?string
    {
        $binary = trim($binary);
        if ($binary === '') {
            return null;
        }
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            $resolved = realpath($binary);

            return is_string($resolved) && is_file($resolved) && is_executable($resolved)
                ? str_replace('\\', '/', $resolved)
                : null;
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binary;
            if (PHP_OS_FAMILY === 'Windows' && !str_ends_with(strtolower($candidate), '.exe')) {
                $windowsCandidate = $candidate . '.exe';
                if (is_file($windowsCandidate) && is_executable($windowsCandidate)) {
                    return str_replace('\\', '/', $windowsCandidate);
                }
            }
            if (is_file($candidate) && is_executable($candidate)) {
                $resolved = realpath($candidate);

                return str_replace('\\', '/', is_string($resolved) ? $resolved : $candidate);
            }
        }

        return null;
    }
}
