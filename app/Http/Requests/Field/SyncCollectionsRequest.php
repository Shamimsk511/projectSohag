<?php

namespace App\Http\Requests\Field;

use App\Services\CollectionSyncService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The batch envelope from the PWA queue (docs/PWA.md). A malformed envelope fails as a whole;
 * each item's own fields are checked one by one by CollectionSyncService.
 */
class SyncCollectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gate on the route; ownership is checked per item
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'max:'.CollectionSyncService::MAX_ITEMS],
            'items.*' => ['required', 'array'],
            'items.*.client_uuid' => ['required', 'uuid', 'distinct:ignore_case'],
        ];
    }

    /**
     * Items as sent (validated() would drop the fields the service validates itself).
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->input('items');
    }
}
