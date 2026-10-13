<?php

namespace App\Models;

use App\Enums\WhatsAppKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A WhatsApp button tap: the chat was opened with the text filled in. Immutable.
 *
 * @property int $id
 * @property WhatsAppKind $kind
 * @property int $party_id
 * @property int|null $schedule_id
 * @property int|null $transaction_id
 * @property string $phone
 * @property int $sent_by
 * @property Carbon $created_at
 */
#[Fillable(['kind', 'party_id', 'schedule_id', 'transaction_id', 'phone', 'sent_by'])]
class WhatsAppLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'whatsapp_logs';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('WhatsApp logs are immutable.'));
        static::deleting(fn () => throw new LogicException('WhatsApp logs are immutable.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => WhatsAppKind::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
