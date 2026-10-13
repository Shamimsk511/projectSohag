<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's secret portal link and PIN. Written only by PortalAccessService.
 *
 * @property int $id
 * @property int $party_id
 * @property string $token_hash
 * @property string $token_encrypted
 * @property string $pin_hash
 * @property bool $must_change_pin
 * @property int $failed_attempts
 * @property Carbon|null $locked_at
 * @property int $issued_by
 * @property Carbon $issued_at
 * @property Carbon|null $last_login_at
 */
#[Fillable(['party_id', 'token_hash', 'token_encrypted', 'pin_hash', 'must_change_pin', 'failed_attempts', 'locked_at', 'issued_by', 'issued_at', 'last_login_at'])]
class PortalAccess extends Model
{
    /** Never in arrays, JSON or audit rows (AuditLogger redacts hidden columns). */
    protected $hidden = ['token_hash', 'token_encrypted', 'pin_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'party_id' => 'integer',
            'must_change_pin' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_at' => 'datetime',
            'issued_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
