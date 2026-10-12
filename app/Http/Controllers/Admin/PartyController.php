<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PartyStatementRequest;
use App\Http\Requests\Admin\PartyTableRequest;
use App\Http\Requests\Admin\StorePartyRequest;
use App\Http\Requests\Admin\UpdatePartyRequest;
use App\Models\Account;
use App\Models\Area;
use App\Models\Party;
use App\Models\PartyAssignment;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\PartyPhotoStore;
use App\Services\PartyService;
use App\Services\PartyTableQuery;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartyController extends Controller
{
    public function __construct(private readonly PartyService $parties) {}

    public function index(): View
    {
        Gate::authorize('viewAny', Party::class);

        return view('admin.parties.index', ['areas' => Area::orderBy('name')->get()]);
    }

    /**
     * DataTables server-side endpoint for the list page.
     */
    public function data(PartyTableRequest $request, PartyTableQuery $table): JsonResponse
    {
        $result = $table->run($request->validated());
        $user = $request->user();

        return response()->json([
            'draw' => $result['draw'],
            'recordsTotal' => $result['recordsTotal'],
            'recordsFiltered' => $result['recordsFiltered'],
            'data' => $result['rows']->map(fn (Party $party): array => [
                'party_code' => $party->party_code,
                'name' => $party->name,
                'phone' => $party->phone,
                'area' => $party->area->name,
                'status' => $party->status->value,
                'status_label' => __('app.statuses.'.$party->status->value),
                'show_url' => route('admin.parties.show', $party),
                'edit_url' => $user?->can('update', $party) ? route('admin.parties.edit', $party) : null,
            ]),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Party::class);

        return view('admin.parties.create', ['party' => new Party, 'areas' => $this->areaOptions(null)]);
    }

    public function store(StorePartyRequest $request): RedirectResponse
    {
        $party = $this->parties->create($request->safe()->except('photo'), $this->takePhotoToken($request), $this->actor());

        return redirect()->route('admin.parties.show', $party)->with('status', __('app.parties.created'));
    }

    public function show(Party $party, LedgerService $ledger): View
    {
        Gate::authorize('view', $party);

        $party->load(['area', 'assignments.fieldOfficer', 'assignments.assignedBy', 'accounts' => fn ($accounts) => $accounts->orderBy('closed_at')->orderBy('id')]);

        $balances = $party->accounts->mapWithKeys(fn (Account $account): array => [$account->id => $ledger->remainingBalance($account)]);

        return view('admin.parties.show', [
            'party' => $party,
            'balances' => $balances,
            'totals' => [
                'saving' => Money::fromPoisha($party->accounts->reject->isLoan()->sum(fn (Account $account): int => $balances[$account->id]->poisha)),
                'loan' => Money::fromPoisha($party->accounts->filter->isLoan()->sum(fn (Account $account): int => $balances[$account->id]->poisha)),
            ],
            'currentAssignment' => $party->assignments->first(fn (PartyAssignment $assignment): bool => $assignment->isActive()),
            'officers' => Gate::allows('assign', $party) ? User::activeFieldOfficers()->orderBy('name')->get() : collect(),
        ]);
    }

    /**
     * Customer ledger: every account of the party (closed ones too), each with its own statement.
     */
    public function statement(PartyStatementRequest $request, Party $party, LedgerService $ledger): View
    {
        $accounts = $party->accounts()->orderBy('closed_at')->orderBy('id')->get();
        $accounts->each(fn (Account $account) => $account->setRelation('party', $party));

        return view('admin.parties.statement', [
            'party' => $party,
            'statements' => $accounts->map(fn (Account $account): array => [
                'account' => $account,
                'statement' => $ledger->statement($account, $request->periodFrom(), $request->periodTo()),
                'remaining' => $ledger->remainingBalance($account),
            ]),
        ]);
    }

    public function edit(Party $party): View
    {
        Gate::authorize('update', $party);

        return view('admin.parties.edit', ['party' => $party, 'areas' => $this->areaOptions($party)]);
    }

    public function update(UpdatePartyRequest $request, Party $party): RedirectResponse
    {
        $this->parties->update($party, $request->safe()->except('photo'), $this->takePhotoToken($request), $this->actor());

        return redirect()->route('admin.parties.show', $party)->with('status', __('app.parties.updated'));
    }

    public function photo(Party $party): StreamedResponse
    {
        Gate::authorize('view', $party);
        abort_if($party->photo_path === null, 404);

        return Storage::disk('local')->response($party->photo_path);
    }

    /**
     * Active areas, plus the party's current one if it has since been deactivated.
     *
     * @return Collection<int, Area>
     */
    private function areaOptions(?Party $party): Collection
    {
        return Area::active()
            ->when($party?->area_id, fn ($query, int $areaId) => $query->orWhere('id', $areaId))
            ->orderBy('name')
            ->get();
    }

    /**
     * The validated upload token, removed from the session so it cannot be claimed twice.
     */
    private function takePhotoToken(Request $request): ?string
    {
        $token = $request->input('photo');

        if (! is_string($token) || $token === '') {
            return null;
        }

        $request->session()->put(
            PartyPhotoStore::SESSION_KEY,
            array_values(array_diff($request->session()->get(PartyPhotoStore::SESSION_KEY, []), [$token])),
        );

        return $token;
    }
}
