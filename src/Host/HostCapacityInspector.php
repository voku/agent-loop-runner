<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class HostCapacityInspector
{
    /**
     * Inspect stdout and stderr from a resource command or probe output.
     */
    public function inspect(string $stdout, string $stderr = '', ?int $now = null): HostCapacityReport
    {
        $currentTime = $now ?? time();
        $metrics = $this->metricsFromOutput($stdout, $stderr, $currentTime);

        if ($metrics !== []) {
            $remainingRatio = min(array_map(
                static fn (array $metric): float => $metric['remaining_ratio'],
                $metrics,
            ));
            $resetAt = $this->latestResetValue($metrics);
            $usagePercent = sprintf('%.1f%%', (1.0 - $remainingRatio) * 100);
            $summary = sprintf('usage %s', $usagePercent);
            if ($resetAt !== null) {
                $seconds = max(0, $resetAt - $currentTime);
                $summary .= sprintf(' (resets in ~%dm)', (int) ceil($seconds / 60));
            }

            return new HostCapacityReport(
                $remainingRatio,
                $resetAt,
                $summary,
                $metrics,
            );
        }

        $limitMetric = $this->usageLimitMetricFromOutput($stdout, $stderr, $currentTime);
        if ($limitMetric !== null) {
            $summary = sprintf('%s limit reached (exhausted)', $limitMetric['label']);
            if ($limitMetric['reset_at'] !== null) {
                $seconds = max(0, $limitMetric['reset_at'] - $currentTime);
                $summary .= sprintf(' (resets in ~%dm)', (int) ceil($seconds / 60));
            }

            return new HostCapacityReport(
                $limitMetric['remaining_ratio'],
                $limitMetric['reset_at'],
                $summary,
                [$limitMetric],
            );
        }

        return new HostCapacityReport(null, null, null);
    }

    /**
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function metricsFromOutput(string $stdout, string $stderr, int $now): array
    {
        $output = trim($stdout);
        if ($output !== '') {
            $decoded = json_decode($output, true);
            if (is_array($decoded)) {
                $metrics = $this->metricsFromJson($decoded, [], $now);
                if ($metrics !== []) {
                    return $metrics;
                }
            }
        }

        $metrics = [];
        foreach ([$this->trimmedOrNull($stdout), $this->trimmedOrNull($stderr)] as $textOutput) {
            if ($textOutput !== null) {
                foreach ($this->metricsFromText($textOutput, $now) as $metric) {
                    $metrics[] = $metric;
                }
            }
        }

        return $this->uniqueMetrics($metrics);
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $path
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function metricsFromJson(array $payload, array $path, int $now): array
    {
        $metrics = [];
        $metric = $this->metricFromArray($payload, $path, $now);
        if ($metric !== null) {
            $metrics[] = $metric;
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            $nextPath = $path;
            $nextPath[] = $key;
            foreach ($this->metricsFromJson($value, $nextPath, $now) as $childMetric) {
                $metrics[] = $childMetric;
            }
        }

        return $this->uniqueMetrics($metrics);
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $path
     * @return array{label: string, remaining_ratio: float, reset_at: int|null}|null
     */
    private function metricFromArray(array $payload, array $path, int $now): ?array
    {
        $remainingRatio = $this->extractRemainingRatio($payload);
        if ($remainingRatio === null) {
            return null;
        }

        $label = $this->metricLabel($payload, $path);
        $resetAt = $this->extractResetAt($payload, $now);

        return [
            'label' => $label,
            'remaining_ratio' => $this->clampRatio($remainingRatio),
            'reset_at' => $resetAt,
        ];
    }

    /**
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function metricsFromText(string $output, int $now): array
    {
        preg_match_all(
            '/^(?P<label>.+?)\s+(?P<percent>\d+(?:\.\d+)?)%\s*(?P<mode>left|remaining|used)?(?:.*?resets?\s+(?P<reset>.+))?$/mi',
            $output,
            $matches,
            PREG_SET_ORDER,
        );

        $metrics = [];
        foreach ($matches as $match) {
            $label = trim((string) $match['label'], " \t\n\r\0\x0B:-");
            $percent = (float) $match['percent'];
            $mode = strtolower(trim((string) ($match['mode'] ?? '')));
            $remainingRatio = $mode === 'used' ? (100.0 - $percent) / 100.0 : $percent / 100.0;
            $metrics[] = [
                'label' => $label !== '' ? $label : 'quota',
                'remaining_ratio' => $this->clampRatio($remainingRatio),
                'reset_at' => $this->parseResetValue($match['reset'] ?? null, $now),
            ];
        }

        return $this->uniqueMetrics($metrics);
    }

    /**
     * @return array{label: string, remaining_ratio: float, reset_at: int|null}|null
     */
    private function usageLimitMetricFromOutput(string $stdout, string $stderr, int $now): ?array
    {
        $combinedOutput = trim($stdout . "\n" . $stderr);
        if ($combinedOutput === '') {
            return null;
        }

        if (preg_match('/hit your usage limit|usage limit reached|rate limit exceeded|quota exceeded|resource has been exhausted|out of credits/i', $combinedOutput) === 1) {
            $resetAt = null;
            if (preg_match('/try again (?:at|in) (?P<reset>.+?)(?:\.|$)/i', $combinedOutput, $matches) === 1
                || preg_match('/resets?\s+(?:at|in)\s+(?P<reset>.+?)(?:\.|$)/i', $combinedOutput, $matches) === 1
            ) {
                $resetAt = $this->parseResetValue($matches['reset'], $now);
            }

            return [
                'label' => 'quota',
                'remaining_ratio' => 0.0,
                'reset_at' => $resetAt,
            ];
        }

        return null;
    }

    /**
     * @param array<mixed> $payload
     */
    private function extractRemainingRatio(array $payload): ?float
    {
        foreach (['remaining_ratio', 'ratio_remaining', 'remainingFraction', 'remaining_fraction'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
        }

        foreach (['remaining_percent', 'percent_remaining', 'remainingPercent', 'left_percent', 'percent_left'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return ((float) $value) / 100.0;
            }
        }

        foreach (['used_percent', 'percent_used', 'usedPercent'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return (100.0 - (float) $value) / 100.0;
            }
        }

        $used = $this->numericField($payload, ['used', 'usage', 'consumed', 'current']);
        $total = $this->numericField($payload, ['total', 'limit', 'quota', 'maximum', 'max']);
        if ($used !== null && $total !== null && $total > 0.0) {
            return max(0.0, ($total - $used) / $total);
        }

        return null;
    }

    /**
     * @param array<mixed> $payload
     */
    private function extractResetAt(array $payload, int $now): ?int
    {
        foreach (['reset_at', 'resets_at', 'next_reset_at', 'resetAt', 'nextResetAt'] as $key) {
            $resetAt = $this->parseResetValue($payload[$key] ?? null, $now);
            if ($resetAt !== null) {
                return $resetAt;
            }
        }

        foreach (['reset_in_seconds', 'resets_in_seconds', 'seconds_until_reset', 'resetInSeconds'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return $now + (int) $value;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $path
     */
    private function metricLabel(array $payload, array $path): string
    {
        foreach (['label', 'name', 'metric', 'window', 'period', 'plan'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if ($path !== []) {
            return $path[array_key_last($path)];
        }

        return 'quota';
    }

    /**
     * @param list<array{label: string, remaining_ratio: float, reset_at: int|null}> $metrics
     */
    private function latestResetValue(array $metrics): ?int
    {
        $resetValues = [];
        foreach ($metrics as $metric) {
            if ($metric['reset_at'] !== null) {
                $resetValues[] = $metric['reset_at'];
            }
        }

        return $resetValues === [] ? null : max($resetValues);
    }

    /**
     * @param list<array{label: string, remaining_ratio: float, reset_at: int|null}> $metrics
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function uniqueMetrics(array $metrics): array
    {
        $unique = [];
        foreach ($metrics as $metric) {
            $key = json_encode([$metric['label'], $metric['remaining_ratio'], $metric['reset_at']]);
            if ($key === false) {
                continue;
            }
            $unique[$key] = $metric;
        }

        return array_values($unique);
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $keys
     */
    private function numericField(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function parseResetValue(mixed $value, int $now): ?int
    {
        if (is_int($value)) {
            return $value > 1000000000000 ? (int) floor($value / 1000) : $value;
        }
        if (is_float($value)) {
            $intValue = (int) round($value);

            return $intValue > 1000000000000 ? (int) floor($intValue / 1000) : $intValue;
        }
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (ctype_digit($trimmed)) {
            $intValue = (int) $trimmed;

            return $intValue > 1000000000000 ? (int) floor($intValue / 1000) : $intValue;
        }

        $relative = $this->relativeSeconds($trimmed);
        if ($relative !== null) {
            return $now + $relative;
        }

        try {
            $timezone = new DateTimeZone('UTC');
            $date = new DateTimeImmutable($trimmed, $timezone);

            return $date->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function relativeSeconds(string $value): ?int
    {
        if (!preg_match_all('/(?P<amount>\d+)\s*(?P<unit>d|h|m|s)/i', $value, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $seconds = 0;
        foreach ($matches as $match) {
            $amount = (int) $match['amount'];
            $unit = strtolower((string) $match['unit']);
            $seconds += match ($unit) {
                'd' => $amount * 86400,
                'h' => $amount * 3600,
                'm' => $amount * 60,
                's' => $amount,
                default => 0,
            };
        }

        return $seconds > 0 ? $seconds : null;
    }

    private function trimmedOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function clampRatio(float $ratio): float
    {
        return max(0.0, min(1.0, $ratio));
    }
}
