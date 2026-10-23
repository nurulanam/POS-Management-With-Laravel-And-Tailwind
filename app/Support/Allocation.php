<?php

namespace App\Support;

use InvalidArgumentException;

class Allocation
{
    /** Exact floor(a*b/d) and remainder, without multiplying large monetary integers. */
    public static function divideProduct(int $a, int $b, int $d): array
    {
        if ($a < 0 || $b < 0 || $d <= 0) {
            throw new InvalidArgumentException('Allocation operands must be non-negative and the divisor positive.');
        }
        $quotient = 0;
        $remainder = 0;
        $part = intdiv($a, $d);
        $rest = $a % $d;
        while ($b > 0) {
            if ($b % 2 === 1) {
                $quotient += $part;
                $remainder += $rest;
                if ($remainder >= $d) {
                    $quotient++;
                    $remainder -= $d;
                }
            }
            $b = intdiv($b, 2);
            if ($b > 0) {
                $part *= 2;
                $rest *= 2;
                if ($rest >= $d) {
                    $part++;
                    $rest -= $d;
                }
            }
        }

        return [$quotient, $remainder];
    }

    public static function roundedShare(int $amount, int $quantity, int $sold): int
    {
        [$whole, $remainder] = self::divideProduct($amount, $quantity, $sold);

        return $whole + ($remainder * 2 >= $sold ? 1 : 0);
    }

    /** Hamilton allocation: ties go to the lowest immutable sale item ID. */
    public static function distribute(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $shares = array_fill_keys(array_keys($weights), 0);
        if ($amount === 0) {
            return $shares;
        }
        if ($amount < 0 || $total <= 0 || min($weights) < 0) {
            throw new InvalidArgumentException('Invalid allocation weights.');
        }
        $remainders = [];
        foreach ($weights as $key => $weight) {
            [$shares[$key], $remainders[$key]] = self::divideProduct($amount, $weight, $total);
        }
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        $left = $amount - array_sum($shares);
        foreach (array_slice($order, 0, $left) as $key) {
            $shares[$key]++;
        }

        return $shares;
    }
}
