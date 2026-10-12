<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SearchRequest;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Top bar member search: JSON suggestions while typing, a redirect on Enter.
 */
class SearchController extends Controller
{
    private const int LIMIT = 8;

    public function __invoke(SearchRequest $request): JsonResponse|RedirectResponse
    {
        $term = $request->term();
        $matches = $term === '' ? collect() : Party::query()
            ->search($term)
            ->with('area')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        if ($request->wantsJson()) {
            return response()->json(['results' => $matches->map(fn (Party $party): array => [
                'name' => $party->name,
                'code' => $party->party_code,
                'client_number' => $party->client_number,
                'phone' => $party->phone,
                'area' => $party->area->name,
                'url' => route('admin.parties.show', $party),
            ])->values()]);
        }

        return $matches->count() === 1
            ? redirect()->route('admin.parties.show', $matches->first())
            : redirect()->route('admin.parties.index', $term === '' ? [] : ['search' => $term]);
    }
}
