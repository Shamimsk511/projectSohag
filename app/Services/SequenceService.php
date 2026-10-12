<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free counters for human-facing numbers. The row lock is held until the caller's DB transaction
 * ends, so a number is consumed only if the record using it is committed.
 */
class SequenceService
{
    /**
     * Next value for $key, starting at 1. Must run inside a DB transaction.
     */
    public function next(string $key): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SequenceService::next() must run inside a DB transaction.');
        }

        DB::table('sequences')->insertOrIgnore(['key' => $key, 'value' => 0]);

        $value = (int) DB::table('sequences')->where('key', $key)->lockForUpdate()->value('value') + 1;
        DB::table('sequences')->where('key', $key)->update(['value' => $value]);

        return $value;
    }
}
