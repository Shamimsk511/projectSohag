<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCollectionPlanRequest;
use App\Models\Account;
use App\Services\CollectionService;
use Illuminate\Http\RedirectResponse;

class CollectionPlanController extends Controller
{
    public function update(SaveCollectionPlanRequest $request, Account $account, CollectionService $collections): RedirectResponse
    {
        $collections->savePlan(
            $account,
            $request->frequency(),
            $request->installment(),
            $request->weekdays(),
            $request->startsOn(),
            $request->endsOn(),
            $this->actor(),
        );

        return redirect()->route('admin.accounts.show', $account)->with('status', __('app.schedules.plan_saved'));
    }
}
