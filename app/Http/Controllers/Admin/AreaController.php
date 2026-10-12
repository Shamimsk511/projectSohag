<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAreaRequest;
use App\Http\Requests\Admin\UpdateAreaRequest;
use App\Models\Area;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AreaController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Area::class);

        return view('admin.areas.index', [
            'areas' => Area::withCount('parties')->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Area::class);

        return view('admin.areas.create', ['area' => new Area]);
    }

    public function store(StoreAreaRequest $request): RedirectResponse
    {
        Area::create($request->validated());

        return redirect()->route('admin.areas.index')->with('status', __('app.areas.created'));
    }

    public function edit(Area $area): View
    {
        Gate::authorize('update', $area);

        return view('admin.areas.edit', ['area' => $area]);
    }

    public function update(UpdateAreaRequest $request, Area $area): RedirectResponse
    {
        $area->update($request->validated());

        return redirect()->route('admin.areas.index')->with('status', __('app.areas.updated'));
    }
}
