<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Diagnostics;

use Closure;
use RuntimeException;

/**
 * Advisory, read-only check of a Contract's declared validation commands.
 *
 * A fresh Runner worktree contains only tracked files of the Contract base
 * commit. A command that names a relative `vendor/` path which is not tracked
 * there cannot run in the worktree. Commands are never interpreted as shell:
 * only plain space-separated tokens are looked at, everything else is
 * reported as not inspectable. The result is an observation, not authority.
 */
final readonly class ValidationCommandInspector
{
    public const OK = 'ok';
    public const MISSING_WORKTREE_DEPENDENCY = 'missing_worktree_dependency';
    public const NOT_INSPECTABLE = 'not_inspectable';

    public const COMPLETENESS_NOTE = 'Coverage of mandatory CI gates (for example static analysis) is not checked; '
        . 'every mandatory gate must be listed as a validation command in the Contract.';

    /** @param Closure(string): bool $isTracked True when the relative path is tracked in the Contract base commit. */
    public function __construct(private Closure $isTracked)
    {
    }

    /**
     * @param list<string> $commands
     * @return list<array{command: string, status: string, path?: string, reason?: string}>
     */
    public function inspect(array $commands): array
    {
        $results = [];
        foreach ($commands as $command) {
            $results[] = $this->inspectOne($command);
        }

        return $results;
    }

    /**
     * @param list<string> $commands
     * @return list<array{command: string, status: string, reason: string}>
     */
    public static function notInspectable(array $commands, string $reason): array
    {
        $results = [];
        foreach ($commands as $command) {
            $results[] = ['command' => $command, 'status' => self::NOT_INSPECTABLE, 'reason' => $reason];
        }

        return $results;
    }

    /** @return array{command: string, status: string, path?: string, reason?: string} */
    private function inspectOne(string $command): array
    {
        if ($command === '' || preg_match('/\A[A-Za-z0-9_.\/:=@+-]+( [A-Za-z0-9_.\/:=@+-]+)*\z/', $command) !== 1) {
            return [
                'command' => $command,
                'status' => self::NOT_INSPECTABLE,
                'reason' => 'command is not a plain space-separated token list (quotes, separators, redirections, expansions or wildcards)',
            ];
        }

        $paths = [];
        foreach (explode(' ', $command) as $token) {
            $path = str_starts_with($token, './') ? substr($token, 2) : $token;
            if (!str_starts_with($path, 'vendor/')) {
                continue;
            }
            if (in_array('..', explode('/', $path), true) || str_contains($path, '//')) {
                return [
                    'command' => $command,
                    'status' => self::NOT_INSPECTABLE,
                    'reason' => 'vendor path is not normalized',
                ];
            }
            $paths[] = $path;
        }

        foreach ($paths as $path) {
            try {
                $tracked = ($this->isTracked)($path);
            } catch (RuntimeException $exception) {
                return [
                    'command' => $command,
                    'status' => self::NOT_INSPECTABLE,
                    'reason' => 'tracked-file lookup failed: ' . $exception->getMessage(),
                ];
            }
            if (!$tracked) {
                return ['command' => $command, 'status' => self::MISSING_WORKTREE_DEPENDENCY, 'path' => $path];
            }
        }

        return ['command' => $command, 'status' => self::OK];
    }
}
