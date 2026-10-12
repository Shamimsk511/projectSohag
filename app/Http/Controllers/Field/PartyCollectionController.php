<?php

namespace App\Http\Controllers\Field;

use App\Enums\ScheduleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Field\StorePartyCollectionsRequest;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Services\CollectionSyncService;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One client on the field list: their photo, and collecting every open account row in one go.
 */
class PartyCollectionController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function create(Party $party): View
    {
        Gate::authorize('collect', $party);

        $rows = CollectionSchedule::query()
            ->onDayListOf($this->actor(), today(config('app.display_timezone')))
            ->where('party_id', $party->id)
            ->whereIn('status', ScheduleStatus::collectable())
            ->with('account')
            ->orderByDesc('due_date')->orderBy('id')
            ->get();
        $collected = $this->ledger->collectedForSchedules($rows->modelKeys());

        return view('field.collections.party', [
            'party' => $party,
            'rows' => $rows->map(function (CollectionSchedule $schedule) use ($collected): array {
                $remaining = $schedule->expected_amount->minus($collected[$schedule->id]);

                return [
                    'schedule' => $schedule,
                    'remaining' => $remaining->isPositive() ? $remaining : Money::zero(),
                    'accountRemaining' => $this->ledger->remainingBalance($schedule->account),
                    'clientUuid' => (string) Str::uuid(),
                ];
            }),
        ]);
    }

    /**
     * Without JS/IndexedDB the form posts here. Each row goes through the offline sync rules on its own,
     * so a rejected row never blocks the others and a resubmit never posts twice (client_uuid).
     */
    public function store(StorePartyCollectionsRequest $request, Party $party, CollectionSyncService $sync): RedirectResponse
    {
        $results = collect($sync->sync($request->syncItems(), $this->actor()));
        [$accepted, $rejected] = $results->partition(fn (array $result): bool => in_array($result['status'], ['created', 'duplicate'], true));

        $redirect = redirect()->route('field.home');
        if ($accepted->isNotEmpty()) {
            $redirect->with('status', __('app.field.saved_many', [
                'count' => $accepted->count(),
                'party' => $party->name,
                'receipts' => $accepted->pluck('receipt_no')->filter()->implode(', '),
            ]));
        }
        if ($rejected->isNotEmpty()) {
            $redirect->with('status_error', $rejected->map(fn (array $result): string => $result['message'] ?? __('app.field.not_saved'))->implode(' '));
        }

        return $redirect;
    }

    public function photo(Party $party): StreamedResponse
    {
        Gate::authorize('viewPhoto', $party);
        abort_if($party->photo_path === null, 404);

        return Storage::disk('local')->response($party->photo_path);
    }
}
