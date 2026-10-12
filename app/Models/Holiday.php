<?php

namespace App\Models;

use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Organisation-wide day without collections. Daily plans skip it; weekly and monthly plans move to
 * the next non-holiday date.
 *
 * @property int $id
 * @property Carbon $date
 * @property string $name
 * @property int $created_by
 */
#[Fillable(['date', 'name', 'created_by'])]
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
