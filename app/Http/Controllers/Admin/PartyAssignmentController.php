<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignPartyRequest;
use App\Models\Party;
use App\Services\AssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class PartyAssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $assignments) {}

    public function store(AssignPartyRequest $request, Party $party): RedirectResponse
    {
        $this->assignments->assign($party, $request->officer(), $this->actor());

        return redirect()->route('admin.parties.show', $party)->with('status', __('app.assignments.assigned'));
    }

    public function destroy(Party $party): RedirectResponse
    {
        Gate::authorize('assign', $party);

        $this->assignments->end($party, $this->actor());

        return redirect()->route('admin.parties.show', $party)->with('status', __('app.assignments.ended'));
    }
}
