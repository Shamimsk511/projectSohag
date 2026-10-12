<?php

namespace App\Models;

use App\Enums\ActiveStatus;
use App\Enums\Gender;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person with one profile, whatever accounts they hold. Never deleted; deactivated instead.
 *
 * @property int $id
 * @property string|null $party_code
 * @property string $name
 * @property string $phone
 * @property string|null $national_id
 * @property Carbon|null $date_of_birth
 * @property Gender|null $gender
 * @property string|null $photo_path
 * @property string|null $nominee_name
 * @property string|null $nominee_relation
 * @property int $area_id
 * @property ActiveStatus $status
 */
#[Fillable([
    'name', 'father_or_husband_name', 'mother_name', 'date_of_birth', 'gender', 'phone', 'national_id',
    'address', 'area_id', 'photo_path', 'nominee_name', 'nominee_relation', 'nominee_phone',
    'nominee_national_id', 'status',
])]
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'status' => ActiveStatus::class,
        ];
    }

    /**
     * Party code for a given id: P-00001.
     */
    public static function codeFor(int $id): string
    {
        return sprintf('P-%05d', $id);
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function isActive(): bool
    {
        return $this->status === ActiveStatus::Active;
    }
}
