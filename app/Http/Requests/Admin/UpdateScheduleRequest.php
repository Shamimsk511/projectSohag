<?php

namespace App\Http\Requests\Admin;

class UpdateScheduleRequest extends StoreScheduleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('schedule')) ?? false;
    }
}
