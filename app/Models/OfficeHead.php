<?php

namespace App\Models;

use App\Enums\ActiveStatus;
use App\Enums\TransactionType;
use Database\Factories\OfficeHeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed label for office income or expense. `kind` is office_income or office_expense.
 * No delete: ledger rows reference it; deactivate instead.
 *
 * @property int $id
 * @property string $name
 * @property TransactionType $kind
 * @property ActiveStatus $status
 */
#[Fillable(['name', 'kind', 'status'])]
class OfficeHead extends Model
{
    /** @use HasFactory<OfficeHeadFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TransactionType::class,
            'status' => ActiveStatus::class,
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ActiveStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === ActiveStatus::Active;
    }
}
