<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignPartyRequest;
use App\Models\Party;
use App\Models\User;
use App\Services\AssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class PartyAssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $assignments) {}

    public function store(AssignPartyRequest $request, Party $party): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->assignments->assign($party, $request->officer(), $actor);

        return redirect()->route('admin.parties.show', $party)->with('status', __('app.assignments.assigned'));
    }

    public function destroy(Party $party): RedirectResponse
    {
        Gate::authorize('assign', $party);

        /** @var User $actor */
        $actor = auth()->user();
        $this->assignments->end($party, $actor);

        return redirect()->route('admin.parties.show', $party)->with('status', __('app.assignments.ended'));
    }
}
