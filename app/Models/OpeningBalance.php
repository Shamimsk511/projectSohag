<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Office cash on hand at go-live, counted from the start of `as_of`. The single row is immutable
 * and sits outside the ledger.
 *
 * @property int $id
 * @property Carbon $as_of
 * @property Money $amount
 * @property string|null $note
 * @property int $set_by
 * @property Carbon $created_at
 */
#[Fillable(['as_of', 'amount', 'note', 'set_by'])]
class OpeningBalance extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Opening balances are immutable.'));
        static::deleting(fn () => throw new LogicException('Opening balances are immutable.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'amount' => Money::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
