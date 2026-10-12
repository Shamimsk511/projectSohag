<?php

namespace Tests\Feature;

use App\Services\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_key_counts_from_one_independently(): void
    {
        $sequences = app(SequenceService::class);

        $values = DB::transaction(fn (): array => [
            $sequences->next('account.SV'),
            $sequences->next('account.SV'),
            $sequences->next('account.LN'),
            $sequences->next('account.SV'),
        ]);

        $this->assertSame([1, 2, 1, 3], $values);
    }

    public function test_a_rolled_back_transaction_does_not_consume_a_number(): void
    {
        $sequences = app(SequenceService::class);

        try {
            DB::transaction(function () use ($sequences): void {
                $sequences->next('account.SV');

                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, DB::transaction(fn (): int => $sequences->next('account.SV')));
    }
}
