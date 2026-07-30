<?php

namespace App\Support;

use InvalidArgumentException;

final class WorkHours
{
    public static function toHundredths(mixed $value): int
    {
        $normalized = trim((string) $value);

        if (! preg_match('/^(?<whole>\d+)(?:\.(?<fraction>\d{1,2}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('勤務時間は小数第2位までの数値で指定してください。');
        }

        $fraction = str_pad($matches['fraction'] ?? '', 2, '0');

        return ((int) $matches['whole'] * 100) + (int) $fraction;
    }

    public static function formatHundredths(int $hundredths): string
    {
        $whole = intdiv($hundredths, 100);
        $fraction = $hundredths % 100;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return sprintf('%d.%s', $whole, rtrim(sprintf('%02d', $fraction), '0'));
    }

    public static function format(mixed $value): string
    {
        return self::formatHundredths(self::toHundredths($value));
    }
}
