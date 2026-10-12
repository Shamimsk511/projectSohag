<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One admin-editable switch. Read and written through SettingService only.
 *
 * @property int $id
 * @property string $key
 * @property string $value
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model {}
