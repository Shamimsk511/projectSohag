<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

class LangFilesTest extends TestCase
{
    public function test_bengali_is_default_with_english_fallback(): void
    {
        $this->assertSame('bn', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Dhaka', config('app.display_timezone'));
    }

    public function test_app_strings_have_bengali_and_english_twins(): void
    {
        $this->assertEqualsCanonicalizing($this->keys('en', 'app'), $this->keys('bn', 'app'));
    }

    public function test_bengali_files_only_use_keys_that_exist_in_english(): void
    {
        foreach (glob(lang_path('bn/*.php')) ?: [] as $file) {
            $group = basename($file, '.php');

            $this->assertSame(
                [],
                array_values(array_diff($this->keys('bn', $group), $this->keys('en', $group))),
                "lang/bn/{$group}.php has keys missing from lang/en/{$group}.php",
            );
        }
    }

    public function test_missing_bengali_validation_message_falls_back_to_english(): void
    {
        $this->assertSame('এই তথ্যের সাথে কোনো অ্যাকাউন্ট মেলেনি।', __('auth.failed'));
        $this->assertStringContainsString('must be a valid JSON string', __('validation.json'));
    }

    /**
     * @return list<string>
     */
    private function keys(string $locale, string $group): array
    {
        /** @var array<string, mixed> $lines */
        $lines = require lang_path("{$locale}/{$group}.php");

        return array_keys(Arr::dot($lines));
    }
}
