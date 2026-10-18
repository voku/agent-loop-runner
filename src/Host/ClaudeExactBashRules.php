<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

use RuntimeException;

/**
 * Turns the approved Contract's validation commands into exact Claude Code `Bash(<command>)` allow rules.
 *
 * Claude matches a rule without a wildcard against the whole command, so the headless builder may run exactly
 * what the Contract declares and nothing else. A command is only representable when it is made of plain tokens:
 * anything that Claude's rule syntax or a shell would interpret (wildcards, parentheses, quotes, expansions,
 * separators, redirections, commas that split the allow list) is refused, and the stage does not start.
 */
final class ClaudeExactBashRules
{
    private const int MAX_COMMAND_LENGTH = 500;

    /**
     * @param list<string> $validationCommands
     * @return list<non-empty-string>
     */
    public static function fromValidation(array $validationCommands): array
    {
        $rules = [];
        foreach ($validationCommands as $command) {
            self::assertRepresentable($command);
            $rules['Bash(' . $command . ')'] = true;
        }

        return array_keys($rules);
    }

    private static function assertRepresentable(string $command): void
    {
        if (
            $command === ''
            || strlen($command) > self::MAX_COMMAND_LENGTH
            || $command[0] === '-'
            || preg_match('~\A[A-Za-z0-9_@%+=:./-]+(?: [A-Za-z0-9_@%+=:./-]+)*\z~', $command) !== 1
        ) {
            throw new RuntimeException(
                'PERMISSION_UNREPRESENTABLE: validation command cannot be granted as an exact Bash permission '
                . '(only single-space separated plain tokens are allowed): ' . substr($command, 0, 120),
            );
        }
    }
}
