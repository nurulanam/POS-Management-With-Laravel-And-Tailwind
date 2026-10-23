<?php

namespace Tests\Unit;

use App\Support\Money;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_money_is_parsed_and_formatted_without_floating_point(): void
    {
        $this->assertSame(101, Money::parse('1.01', 'BDT'));
        $this->assertSame(110, Money::parse('1.1', 'BDT'));
        $this->assertSame(100, Money::parse('100', 'JPY'));
        $this->assertSame('BDT 0.01', Money::format(1, 'BDT'));
        $this->assertSame('JPY 101', Money::format(101, 'JPY'));
        $this->assertSame(750, Money::basisPoints('7.50'));
    }
}
