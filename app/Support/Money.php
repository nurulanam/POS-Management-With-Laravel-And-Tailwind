<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function precision(string $currency): int
    {
        return $currency === 'JPY' ? 0 : 2;
    }

    public static function parse(string $value, string $currency, string $field = 'price', int $maximum = 1_000_000_000): int
    {
        $digits = self::precision($currency);
        $pattern = $digits === 0 ? '/^\d{1,13}$/' : '/^\d{1,13}(?:\.\d{1,2})?$/';
        if (! preg_match($pattern, $value)) {
            throw ValidationException::withMessages([$field => "Enter a non-negative amount with at most {$digits} decimal places."]);
        }
        $parts = explode('.', $value);
        $minor = (int) $parts[0] * (10 ** $digits) + ($digits ? (int) str_pad($parts[1] ?? '', $digits, '0') : 0);
        if ($minor > $maximum) {
            throw ValidationException::withMessages([$field => 'The amount exceeds the supported limit.']);
        }

        return $minor;
    }

    public static function decimal(int $minor, string $currency): string
    {
        $digits = self::precision($currency);
        if ($minor < 0) {
            return '-'.self::decimal(-$minor, $currency);
        }
        if ($digits === 0) {
            return (string) $minor;
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $minor, string $currency): string
    {
        return $currency.' '.self::decimal($minor, $currency);
    }

    public static function basisPoints(string $percent): int
    {
        return self::parse($percent, 'BDT', 'tax_rate', 10_000);
    }
}
