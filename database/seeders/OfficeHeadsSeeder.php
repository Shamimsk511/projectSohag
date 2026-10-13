<?php

namespace Database\Seeders;

use App\Enums\TransactionType;
use App\Models\OfficeHead;
use Illuminate\Database\Seeder;

/**
 * Starting heads taken from the NGO's old cash book. Idempotent; the office adds more in the UI.
 */
class OfficeHeadsSeeder extends Seeder
{
    public function run(): void
    {
        $heads = [
            TransactionType::OfficeIncome->value => ['ভর্তি ফি', 'ফরম ফি', 'অফিস আদায়', 'অন্যান্য আয়'],
            TransactionType::OfficeExpense->value => ['বেতন', 'বিদ্যুৎ বিল', 'অফিস খরচ', 'অন্যান্য খরচ'],
        ];

        foreach ($heads as $kind => $names) {
            foreach ($names as $name) {
                OfficeHead::firstOrCreate(['name' => $name], ['kind' => $kind]);
            }
        }
    }
}
