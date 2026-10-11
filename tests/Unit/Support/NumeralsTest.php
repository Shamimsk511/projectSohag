<?php

namespace Tests\Unit\Support;

use App\Support\Numerals;
use PHPUnit\Framework\TestCase;

class NumeralsTest extends TestCase
{
    public function test_localizes_digits_for_bengali(): void
    {
        $this->assertSame('১২/১০/২০২৬', Numerals::localize('12/10/2026', 'bn'));
    }

    public function test_leaves_digits_for_other_locales(): void
    {
        $this->assertSame('12/10/2026', Numerals::localize('12/10/2026', 'en'));
    }

    public function test_converts_bengali_digits_to_ascii(): void
    {
        $this->assertSame('1234.50', Numerals::toAscii('১২৩৪.৫০'));
    }
}
