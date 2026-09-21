<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Tests\Unit\Host;

use PHPUnit\Framework\TestCase;
use voku\AgentLoopRunner\Host\HostCapacityInspector;

final class HostCapacityInspectorTest extends TestCase
{
    private HostCapacityInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new HostCapacityInspector();
    }

    public function testParsesJsonRemainingPercent(): void
    {
        $json = json_encode([
            'session' => [
                'remaining_percent' => 80,
                'reset_in_seconds' => 3600,
            ],
        ], JSON_THROW_ON_ERROR);

        $now = 1758441600;
        $report = $this->inspector->inspect($json, '', $now);

        self::assertNotNull($report->remainingRatio);
        self::assertEqualsWithDelta(0.8, $report->remainingRatio, 0.001);
        self::assertNotNull($report->usageRatio());
        self::assertEqualsWithDelta(0.2, $report->usageRatio(), 0.001);
        self::assertFalse($report->isNearLimit(0.95));
        self::assertSame($now + 3600, $report->resetAt);
    }

    public function testParsesJsonUsedPercentNearLimit(): void
    {
        $json = json_encode([
            'metrics' => [
                'used_percent' => 96.5,
                'reset_in_seconds' => 1800,
            ],
        ], JSON_THROW_ON_ERROR);

        $now = 1758441600;
        $report = $this->inspector->inspect($json, '', $now);

        self::assertNotNull($report->remainingRatio);
        self::assertEqualsWithDelta(0.035, $report->remainingRatio, 0.001);
        self::assertNotNull($report->usageRatio());
        self::assertEqualsWithDelta(0.965, $report->usageRatio(), 0.001);
        self::assertTrue($report->isNearLimit(0.95));
        self::assertFalse($report->isNearLimit(0.98));
        self::assertStringContainsString('96.5%', (string) $report->summary);
    }

    public function testParsesJsonUsedVersusTotal(): void
    {
        $json = json_encode([
            'used' => 980,
            'total' => 1000,
            'reset_at' => 1758450000,
        ], JSON_THROW_ON_ERROR);

        $report = $this->inspector->inspect($json, '', 1758441600);

        self::assertNotNull($report->remainingRatio);
        self::assertEqualsWithDelta(0.02, $report->remainingRatio, 0.001);
        self::assertNotNull($report->usageRatio());
        self::assertEqualsWithDelta(0.98, $report->usageRatio(), 0.001);
        self::assertTrue($report->isNearLimit(0.95));
        self::assertSame(1758450000, $report->resetAt);
    }

    public function testParsesTextPercentageOutput(): void
    {
        $text = "codex-5.5 97.5% used resets 2h\n";
        $now = 1758441600;
        $report = $this->inspector->inspect($text, '', $now);

        self::assertNotNull($report->remainingRatio);
        self::assertEqualsWithDelta(0.025, $report->remainingRatio, 0.001);
        self::assertNotNull($report->usageRatio());
        self::assertEqualsWithDelta(0.975, $report->usageRatio(), 0.001);
        self::assertTrue($report->isNearLimit(0.95));
        self::assertSame($now + 7200, $report->resetAt);
    }

    public function testParsesRemainingModeInText(): void
    {
        $text = "claude 10% remaining resets 30m\n";
        $now = 1758441600;
        $report = $this->inspector->inspect($text, '', $now);

        self::assertNotNull($report->remainingRatio);
        self::assertEqualsWithDelta(0.1, $report->remainingRatio, 0.001);
        self::assertEqualsWithDelta(0.9, (float) $report->usageRatio(), 0.001);
        self::assertFalse($report->isNearLimit(0.95));
        self::assertSame($now + 1800, $report->resetAt);
    }

    public function testParsesUsageLimitHitString(): void
    {
        $text = "You have hit your usage limit. Please try again at 2026-09-21 12:00:00 UTC.";
        $report = $this->inspector->inspect('', $text, 1758441600);

        self::assertSame(0.0, $report->remainingRatio);
        self::assertSame(1.0, $report->usageRatio());
        self::assertTrue($report->isNearLimit(0.95));
        self::assertNotNull($report->resetAt);
        self::assertStringContainsString('limit reached (exhausted)', (string) $report->summary);
    }

    public function testUnparseableOutputReturnsNullCapacity(): void
    {
        $report = $this->inspector->inspect('codex-cli 0.155.1', '', 1758441600);

        self::assertNull($report->remainingRatio);
        self::assertNull($report->usageRatio());
        self::assertFalse($report->isNearLimit(0.95));
    }
}
