<?php

namespace Tests\Unit\Support;

use App\Support\WhatsApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhatsAppTest extends TestCase
{
    public function test_link_uses_the_international_number_and_encodes_the_text(): void
    {
        $this->assertSame(
            'https://wa.me/8801711223344?text=%E0%A6%B9%E0%A7%8D%E0%A6%AF%E0%A6%BE%E0%A6%81%20%E0%A7%B3%20%E0%A7%AB%E0%A7%A6%E0%A7%A6%0Aok',
            WhatsApp::link('01711223344', "হ্যাঁ ৳ ৫০০\nok"),
        );
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function invalidPhones(): array
    {
        return ['null' => [null], 'empty' => [''], 'short' => ['0171122334'], 'landline' => ['0211223344'], 'already international' => ['8801711223344']];
    }

    #[DataProvider('invalidPhones')]
    public function test_no_link_without_a_valid_mobile_number(?string $phone): void
    {
        $this->assertNull(WhatsApp::link($phone, 'hi'));
    }
}
