<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoopRunner\Diagnostics\ValidationCommandInspector;

final class ValidationCommandInspectorTest extends TestCase
{
    /** @param list<string> $tracked */
    private function inspector(array $tracked = ['bin/check.sh']): ValidationCommandInspector
    {
        return new ValidationCommandInspector(static fn (string $path): bool => in_array($path, $tracked, true));
    }

    public function testSelfContainedCommandsAreOk(): void
    {
        $results = $this->inspector()->inspect(['php -v', 'sh bin/check.sh', 'composer ci']);

        foreach ($results as $result) {
            self::assertSame('ok', $result['status']);
        }
    }

    #[DataProvider('vendorCommands')]
    public function testUntrackedVendorPathIsMissing(string $command, string $path): void
    {
        $result = $this->inspector()->inspect([$command])[0];

        self::assertSame('missing_worktree_dependency', $result['status']);
        self::assertSame($path, $result['path'] ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function vendorCommands(): iterable
    {
        yield 'relative' => ['php vendor/bin/phpunit tests', 'vendor/bin/phpunit'];
        yield 'dot slash' => ['./vendor/bin/phpstan analyse', 'vendor/bin/phpstan'];
        yield 'option value' => ['php vendor/bin/phpunit --colors=never', 'vendor/bin/phpunit'];
    }

    public function testTrackedVendorPathIsOk(): void
    {
        $result = $this->inspector(['vendor/bin/tool'])->inspect(['php vendor/bin/tool'])[0];

        self::assertSame('ok', $result['status']);
    }

    #[DataProvider('shellSyntax')]
    public function testShellSyntaxIsNeverClassified(string $command): void
    {
        $result = $this->inspector()->inspect([$command])[0];

        self::assertSame('not_inspectable', $result['status']);
        self::assertArrayHasKey('reason', $result);
        self::assertArrayNotHasKey('path', $result);
    }

    /** @return iterable<string, array{string}> */
    public static function shellSyntax(): iterable
    {
        yield 'single quote' => ["php -r 'echo 1;' vendor/bin/x"];
        yield 'double quote' => ['php "vendor/bin/x"'];
        yield 'and' => ['php -v && php vendor/bin/x'];
        yield 'semicolon' => ['php -v; php -v'];
        yield 'pipe' => ['php -v | cat'];
        yield 'redirect' => ['php -v > out.txt'];
        yield 'expansion' => ['php $HOME/vendor/bin/x'];
        yield 'subshell' => ['php $(pwd)/x'];
        yield 'wildcard' => ['php vendor/bin/*'];
        yield 'double space' => ['php  -v'];
        yield 'empty' => [''];
        yield 'traversal' => ['php vendor/../bin/check.sh'];
    }

    public function testLookupFailureIsNotInspectable(): void
    {
        $inspector = new ValidationCommandInspector(static function (string $path): bool {
            throw new RuntimeException('boom');
        });

        $result = $inspector->inspect(['php vendor/bin/x'])[0];

        self::assertSame('not_inspectable', $result['status']);
    }

    public function testNotInspectableHelperAndNote(): void
    {
        $results = ValidationCommandInspector::notInspectable(['a', 'b'], 'why');

        self::assertCount(2, $results);
        self::assertSame('why', $results[1]['reason']);
        self::assertStringContainsString('not checked', ValidationCommandInspector::COMPLETENESS_NOTE);
    }
}
