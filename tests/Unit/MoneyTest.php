<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_converts_to_and_from_cents_without_float_drift(): void
    {
        $this->assertSame(1234, Money::toCents(12.34));
        $this->assertSame(1000, Money::toCents('10.00'));
        $this->assertSame(105, Money::toCents(1.05));
        $this->assertSame(0, Money::toCents(null));
        $this->assertSame('12.34', Money::fromCents(1234));
        $this->assertSame('0.05', Money::fromCents(5));
    }

    public function test_adding_decimal_amounts_as_cents_is_exact(): void
    {
        // The reason every amount in the POS is an integer: binary floats
        // cannot represent 0.1, so 0.1 + 0.2 is not 0.3 and a till that
        // accumulates that error is a till nobody trusts.
        $this->assertNotEquals(0.3, 0.1 + 0.2);
        $this->assertSame(30, Money::toCents(0.1) + Money::toCents(0.2));
    }

    public function test_it_rounds_percentages_to_the_nearest_cent(): void
    {
        $this->assertSame(100, Money::percentOf(1000, 10));
        $this->assertSame(333, Money::percentOf(999, 33.333));
        $this->assertSame(0, Money::percentOf(100, 0));
    }

    public function test_it_caps_a_discount_at_the_amount_being_discounted(): void
    {
        $this->assertSame(500, Money::cap(500, 1000));
        $this->assertSame(1000, Money::cap(5000, 1000));
        $this->assertSame(0, Money::cap(-100, 1000));
    }

    public function test_it_formats_amounts_for_display(): void
    {
        $this->assertSame('12.50', Money::format(12.5));
        $this->assertSame('$12.50', Money::format(12.5, '$'));
        $this->assertSame('12.50', Money::format('12.499'));
    }
}
