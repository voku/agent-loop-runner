<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

final readonly class BinaryLocator
{
    /**
     * @param array<string, string> $environment
     * @return non-empty-string|null
     */
    public function locate(string $binary, array $environment, string $workingDirectory): ?string
    {
        $binary = trim($binary);
        $workingDirectory = realpath($workingDirectory) ?: '';
        if ($binary === '' || $workingDirectory === '') {
            return null;
        }
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            $candidate = $this->absolute($binary)
                ? $binary
                : $workingDirectory . DIRECTORY_SEPARATOR . $binary;
            $resolved = realpath($candidate);

            return is_string($resolved) && is_file($resolved) && is_executable($resolved)
                ? str_replace('\\', '/', $resolved)
                : null;
        }

        $path = $environment['PATH'] ?? null;
        if (!is_string($path) || $path === '') {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            if (!$this->absolute($directory)) {
                $directory = $workingDirectory . DIRECTORY_SEPARATOR . $directory;
            }
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binary;
            if (PHP_OS_FAMILY === 'Windows' && !str_ends_with(strtolower($candidate), '.exe')) {
                $windowsCandidate = $candidate . '.exe';
                if (is_file($windowsCandidate) && is_executable($windowsCandidate)) {
                    $resolved = realpath($windowsCandidate);
                    return str_replace('\\', '/', is_string($resolved) ? $resolved : $windowsCandidate);
                }
            }
            if (is_file($candidate) && is_executable($candidate)) {
                $resolved = realpath($candidate);

                return str_replace('\\', '/', is_string($resolved) ? $resolved : $candidate);
            }
        }

        return null;
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
