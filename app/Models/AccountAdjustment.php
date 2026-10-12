<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\AccountAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Record of a contract amount change: old and new value, why, and who. Never edited.
 *
 * @property int $id
 * @property int $account_id
 * @property Money $old_amount
 * @property Money $new_amount
 * @property string $reason
 * @property int $created_by
 * @property Carbon $created_at
 */
#[Fillable(['account_id', 'old_amount', 'new_amount', 'reason', 'created_by'])]
class AccountAdjustment extends Model
{
    /** @use HasFactory<AccountAdjustmentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_amount' => Money::class,
            'new_amount' => Money::class,
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
