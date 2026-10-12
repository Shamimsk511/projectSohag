<?php

namespace App\Http\Requests\Field;

use App\Enums\PaymentMethod;
use App\Models\Party;
use App\Services\CollectionSyncService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Collect all" for one client without JS: one item per account row, blank amount = skipped.
 * Amounts and rows are checked per item by CollectionSyncService.
 */
class StorePartyCollectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Party $party */
        $party = $this->route('party');

        return $this->user()?->can('collect', $party) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:'.CollectionSyncService::MAX_ITEMS],
            'items.*.schedule_id' => ['required', 'integer'],
            'items.*.amount' => ['nullable', 'string'],
            'items.*.client_uuid' => ['required', 'uuid', 'distinct'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('items') && $this->syncItems() === []) {
                $validator->errors()->add('items', __('app.field.nothing_to_collect'));
            }
        }];
    }

    /**
     * Filled rows as offline-sync items, collected now.
     *
     * @return list<array<string, mixed>>
     */
    public function syncItems(): array
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = (array) $this->input('items', []);
        $collectedAt = now()->toIso8601String();

        return collect($items)
            ->filter(fn (array $item): bool => trim((string) ($item['amount'] ?? '')) !== '')
            ->map(fn (array $item): array => [
                'client_uuid' => (string) $item['client_uuid'],
                'schedule_id' => $item['schedule_id'],
                'amount' => trim((string) $item['amount']),
                'payment_method' => $this->input('payment_method'),
                'description' => $this->input('description'),
                'collected_at' => $collectedAt,
            ])
            ->values()
            ->all();
    }
}
