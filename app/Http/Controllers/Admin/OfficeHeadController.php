<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOfficeHeadRequest;
use App\Http\Requests\Admin\UpdateOfficeHeadRequest;
use App\Models\OfficeHead;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OfficeHeadController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('viewAny', OfficeHead::class);

        return view('admin.office-heads.index', [
            'heads' => OfficeHead::query()->orderBy('kind')->orderBy('name')->paginate(50),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', OfficeHead::class);

        return view('admin.office-heads.create', ['head' => new OfficeHead]);
    }

    public function store(StoreOfficeHeadRequest $request): RedirectResponse
    {
        DB::transaction(fn () => $this->audit->created('office_head.created', OfficeHead::create($request->validated()), $this->actor()));

        return redirect()->route('admin.office-heads.index')->with('status', __('app.office_heads.created'));
    }

    public function edit(OfficeHead $officeHead): View
    {
        Gate::authorize('update', $officeHead);

        return view('admin.office-heads.edit', ['head' => $officeHead]);
    }

    public function update(UpdateOfficeHeadRequest $request, OfficeHead $officeHead): RedirectResponse
    {
        DB::transaction(function () use ($officeHead, $request): void {
            $officeHead->update($request->validated());
            $this->audit->updated('office_head.updated', $officeHead, $this->actor());
        });

        return redirect()->route('admin.office-heads.index')->with('status', __('app.office_heads.updated'));
    }
}
