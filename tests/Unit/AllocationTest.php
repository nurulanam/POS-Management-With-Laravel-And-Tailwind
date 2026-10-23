<?php

namespace Tests\Unit;

use App\Support\Allocation;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class AllocationTest extends TestCase
{
    public function test_large_integer_products_do_not_overflow_or_lose_cents(): void
    {
        $this->assertSame([999999999998, 1], Allocation::divideProduct(999999999999, 999999999999, 1000000000000));
        $this->assertSame([1 => 333333333333, 2 => 666666666667], Allocation::distribute(1000000000000, [1 => 333333333333, 2 => 666666666667]));
    }

    public function test_largest_remainder_allocations_are_exact_and_stable(): void
    {
        $this->assertSame([10 => 1, 20 => 0, 30 => 0], Allocation::distribute(1, [10 => 1, 20 => 1, 30 => 1]));
        $this->assertSame([10 => 2, 20 => 1, 30 => 1], Allocation::distribute(4, [10 => 3, 20 => 2, 30 => 2]));
        $this->assertSame([1 => 0, 2 => 0], Allocation::distribute(0, [1 => 0, 2 => 0]));
    }

    public function test_split_returns_exhaust_every_minor_unit_without_rounding_drift(): void
    {
        foreach ([0, 1, 2, 187, 10001, 1000000000000] as $amount) {
            foreach ([1, 2, 3, 7, 10000] as $sold) {
                $refunded = 0;
                for ($i = 1; $i <= $sold; $i++) {
                    $portion = Allocation::roundedShare($amount, $i, $sold) - Allocation::roundedShare($amount, $i - 1, $sold);
                    $this->assertGreaterThanOrEqual(0, $portion);
                    $refunded += $portion;
                }
                $this->assertSame($amount, $refunded);
            }
        }
    }

    public function test_negative_net_reports_format_correctly(): void
    {
        $this->assertSame('BDT -0.01', Money::format(-1, 'BDT'));
        $this->assertSame('BDT -26.88', Money::format(-2688, 'BDT'));
        $this->assertSame('JPY -123', Money::format(-123, 'JPY'));
    }
}
