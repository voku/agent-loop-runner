<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit;

use PHPUnit\Framework\TestCase;
use voku\AgentLoopRunner\RunnerLayout;

final class RunnerLayoutTest extends TestCase
{
    public function testDistinctIdentifiersNeverCollapseToSamePrivatePath(): void
    {
        $root = sys_get_temp_dir() . '/runner-layout-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($root));
        try {
            $layout = new RunnerLayout($root);
            $long = str_repeat('a', 64);

            self::assertNotSame($layout->runtime($long . '1'), $layout->runtime($long . '2'));
            self::assertNotSame($layout->runtime('Task'), $layout->runtime('task'));
            self::assertNotSame($layout->runtime('///'), $layout->runtime('\\\\\\'));
            self::assertLessThanOrEqual(64, strlen(pathinfo($layout->runtime($long . '1'), PATHINFO_FILENAME)));
        } finally {
            rmdir($root);
        }
    }
}
