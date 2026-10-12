<?php

namespace App\Models;

use Database\Factories\DailyClosingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A closed business date. Only admin may post transactions or cash handovers dated on it.
 * Immutable: there is no reopen.
 *
 * @property int $id
 * @property Carbon $date
 * @property int $closed_by
 * @property Carbon $created_at
 */
#[Fillable(['date', 'closed_by'])]
class DailyClosing extends Model
{
    /** @use HasFactory<DailyClosingFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Daily closings are immutable.'));
        static::deleting(fn () => throw new LogicException('Daily closings are immutable.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * Whether the Dhaka business date is closed. Compared as a range because SQLite keeps the date
     * cast as "Y-m-d 00:00:00".
     */
    public static function isClosed(Carbon $date): bool
    {
        return self::query()
            ->where('date', '>=', $date->toDateString())
            ->where('date', '<', $date->copy()->addDay()->toDateString())
            ->exists();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
