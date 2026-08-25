<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function it_normalises_input_to_two_decimal_places(): void
    {
        $this->assertSame('280000.00', Money::of('280000'));
        $this->assertSame('1250.50', Money::of('1,250.5'));
        $this->assertSame('0.00', Money::of(null));
        $this->assertSame('0.00', Money::of(''));
        $this->assertSame('42000.00', Money::of(42000));
    }

    #[Test]
    public function it_rejects_values_that_are_not_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('not money');
    }

    #[Test]
    public function it_adds_and_subtracts_without_floating_point_drift(): void
    {
        // 0.1 + 0.2 would be 0.30000000000000004 in float arithmetic.
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('300000.00', Money::add('280000', '20000'));
        $this->assertSame('90000.00', Money::sub('300000', '210000'));
        $this->assertSame('-5000.00', Money::sub('280000', '285000'));
    }

    #[Test]
    public function it_multiplies_and_divides_with_half_up_rounding(): void
    {
        $this->assertSame('6400.00', Money::mul('3200', '2'));
        $this->assertSame('32000.00', Money::mul('6400', '5'));

        // 6500 / 3 = 2166.666… rounds to 2166.67
        $this->assertSame('2166.67', Money::div('6500', '3'));
        $this->assertSame('0.00', Money::div('100', '0'));
    }

    #[Test]
    public function it_calculates_a_percentage_of_an_amount(): void
    {
        $this->assertSame('15000.00', Money::percentOf('50000', '30'));
        $this->assertSame('25000.00', Money::percentOf('50000', '50'));
        $this->assertSame('10000.00', Money::percentOf('50000', '20'));
    }

    #[Test]
    public function it_calculates_a_ratio_as_a_percentage_without_losing_precision(): void
    {
        // Dividing first at two decimals would give 22, not 21.67.
        $this->assertSame(21.67, Money::percentage('65000', '300000'));
        $this->assertSame(81.67, Money::percentage('24500', '30000'));
        $this->assertSame(0.0, Money::percentage('100', '0'));
    }

    #[Test]
    public function it_splits_an_amount_so_the_parts_always_sum_back(): void
    {
        $slices = Money::split('90000', 4);
        $this->assertSame(['22500.00', '22500.00', '22500.00', '22500.00'], $slices);
        $this->assertSame('90000.00', Money::sum($slices));

        // An amount that does not divide evenly gives its spare cents away.
        $awkward = Money::split('100', 3);
        $this->assertSame(['33.34', '33.33', '33.33'], $awkward);
        $this->assertSame('100.00', Money::sum($awkward));
    }

    #[Test]
    public function it_compares_amounts(): void
    {
        $this->assertTrue(Money::gt('100', '99.99'));
        $this->assertTrue(Money::lt('99.99', '100'));
        $this->assertTrue(Money::gte('100', '100'));
        $this->assertTrue(Money::isZero('0.00'));
        $this->assertTrue(Money::isNegative('-0.01'));
        $this->assertFalse(Money::isNegative('0.00'));
    }

    #[Test]
    public function it_never_produces_negative_zero(): void
    {
        $this->assertSame('0.00', Money::sub('100', '100'));
        $this->assertSame('0.00', Money::mul('0', '-5'));
    }

    #[Test]
    public function it_clamps_negatives_to_zero_when_asked(): void
    {
        $this->assertSame('0.00', Money::floorAtZero('-2500'));
        $this->assertSame('2500.00', Money::floorAtZero('2500'));
    }

    #[DataProvider('sumProvider')]
    #[Test]
    public function it_sums_collections(array $values, string $expected): void
    {
        $this->assertSame($expected, Money::sum($values));
    }

    public static function sumProvider(): array
    {
        return [
            'the example fixed expenses' => [['3000', '42000', '10000', '10000'], '65000.00'],
            'empty' => [[], '0.00'],
            'with negatives' => [['100', '-40'], '60.00'],
        ];
    }
}
