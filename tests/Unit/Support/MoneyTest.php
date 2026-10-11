<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function takaInputs(): array
    {
        return [
            'whole' => ['1234', 123400],
            'one decimal' => ['1234.5', 123450],
            'two decimals' => ['1234.05', 123405],
            'grouped' => ['1,23,456.75', 12345675],
            'spaces trimmed' => [' 50 ', 5000],
            'bengali digits' => ['১২৩৪.৫০', 123450],
            'negative' => ['-10.25', -1025],
            'zero' => ['0', 0],
            'leading dot' => ['.5', 50],
        ];
    }

    #[DataProvider('takaInputs')]
    public function test_parses_taka_strings_without_floats(string $input, int $poisha): void
    {
        $this->assertSame($poisha, Money::fromTaka($input)->poisha);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'empty' => [''],
            'letters' => ['abc'],
            'three decimals' => ['1.005'],
            'two dots' => ['1.2.3'],
            'only dot' => ['.'],
            'too large' => ['10000000000000000'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_taka_strings(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromTaka($input);
    }

    public function test_to_taka_string_is_plain_decimal(): void
    {
        $this->assertSame('1234.05', Money::fromPoisha(123405)->toTaka());
        $this->assertSame('-0.50', Money::fromPoisha(-50)->toTaka());
        $this->assertSame('0.00', Money::fromPoisha(0)->toTaka());
    }

    public function test_formats_with_south_asian_grouping_in_english(): void
    {
        $this->assertSame('৳12,34,567.89', Money::fromPoisha(123456789)->format('en'));
        $this->assertSame('৳999.00', Money::fromPoisha(99900)->format('en'));
        $this->assertSame('৳1,000.00', Money::fromPoisha(100000)->format('en'));
        $this->assertSame('-৳1,00,000.50', Money::fromPoisha(-10000050)->format('en'));
    }

    public function test_formats_with_bengali_digits_in_bengali(): void
    {
        $this->assertSame('৳১২,৩৪,৫৬৭.৮৯', Money::fromPoisha(123456789)->format('bn'));
    }

    public function test_arithmetic_returns_new_instances(): void
    {
        $a = Money::fromPoisha(1000);
        $b = Money::fromPoisha(250);

        $this->assertSame(1250, $a->plus($b)->poisha);
        $this->assertSame(750, $a->minus($b)->poisha);
        $this->assertSame(1000, $a->poisha);
    }

    public function test_comparisons(): void
    {
        $this->assertTrue(Money::fromPoisha(1)->isPositive());
        $this->assertFalse(Money::fromPoisha(0)->isPositive());
        $this->assertTrue(Money::fromPoisha(0)->isZero());
        $this->assertTrue(Money::fromPoisha(-1)->isNegative());
        $this->assertTrue(Money::fromPoisha(5)->equals(Money::fromPoisha(5)));
        $this->assertTrue(Money::fromPoisha(6)->greaterThan(Money::fromPoisha(5)));
    }

    public function test_eloquent_cast_round_trips_poisha(): void
    {
        $caster = Money::castUsing([]);
        $model = new class extends Model {};

        $this->assertSame(123405, $caster->set($model, 'amount', Money::fromPoisha(123405), []));
        $this->assertNull($caster->set($model, 'amount', null, []));

        $money = $caster->get($model, 'amount', '123405', []);
        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame(123405, $money->poisha);
        $this->assertNull($caster->get($model, 'amount', null, []));
    }

    public function test_eloquent_cast_rejects_raw_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::castUsing([])->set(new class extends Model {}, 'amount', 1234, []);
    }
}
