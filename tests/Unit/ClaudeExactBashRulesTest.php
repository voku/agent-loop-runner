<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\Host\ClaudeExactBashRules;

final class ClaudeExactBashRulesTest extends TestCase
{
    public function testEachValidationCommandBecomesOneExactRuleWithoutDuplicates(): void
    {
        self::assertSame(
            [
                'Bash(vendor/bin/phpunit tests/FooTest.php tests/BarTest.php)',
                'Bash(composer run-script test:unit --timeout=0)',
            ],
            ClaudeExactBashRules::fromValidation([
                'vendor/bin/phpunit tests/FooTest.php tests/BarTest.php',
                'composer run-script test:unit --timeout=0',
                'vendor/bin/phpunit tests/FooTest.php tests/BarTest.php',
            ]),
        );
        self::assertSame([], ClaudeExactBashRules::fromValidation([]));
    }

    /** @return iterable<string, array{string}> */
    public static function unrepresentableCommands(): iterable
    {
        yield 'wildcard' => ['vendor/bin/phpunit tests/*'];
        yield 'prefix wildcard' => ['vendor/bin/phpunit:*'];
        yield 'parentheses' => ['vendor/bin/phpunit (tests)'];
        yield 'double quotes' => ['php -r "exit(0);"'];
        yield 'single quotes' => ["php -r 'exit(0);'"];
        yield 'chain with and' => ['vendor/bin/phpunit && rm -rf /'];
        yield 'chain with semicolon' => ['vendor/bin/phpunit; id'];
        yield 'pipe' => ['vendor/bin/phpunit | tee out'];
        yield 'redirect' => ['vendor/bin/phpunit > out.txt'];
        yield 'command substitution' => ['vendor/bin/phpunit $(id)'];
        yield 'backtick' => ['vendor/bin/phpunit `id`'];
        yield 'comma splits the allow list' => ['vendor/bin/phpunit --filter a,b'];
        yield 'newline' => ["vendor/bin/phpunit\nid"];
        yield 'backslash' => ['vendor/bin/phpunit tests\\Foo.php'];
        yield 'double space' => ['vendor/bin/phpunit  tests/FooTest.php'];
        yield 'leading space' => [' vendor/bin/phpunit'];
        yield 'trailing space' => ['vendor/bin/phpunit '];
        yield 'leading dash would be read as a flag' => ['-vendor/bin/phpunit'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 501)];
    }

    #[DataProvider('unrepresentableCommands')]
    public function testUnrepresentableCommandFailsClosed(string $command): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PERMISSION_UNREPRESENTABLE');
        ClaudeExactBashRules::fromValidation(['vendor/bin/phpunit tests/FooTest.php', $command]);
    }
}
