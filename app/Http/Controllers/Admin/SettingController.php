<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Admin-only system settings.
 */
class SettingController extends Controller
{
    public function edit(SettingService $settings): View
    {
        Gate::authorize(Permission::ManageSettings->value);

        return view('admin.settings.edit', [
            'cashHandoverMode' => $settings->cashHandoverMode(),
            'autoCloseDays' => $settings->autoCloseDays(),
        ]);
    }

    public function update(UpdateSettingsRequest $request, SettingService $settings): RedirectResponse
    {
        $settings->setCashHandoverMode($request->cashHandoverMode(), $this->actor());
        $settings->setAutoCloseDays($request->boolean('auto_close_days'), $this->actor());

        return redirect()->route('admin.settings.edit')->with('status', __('app.settings.saved'));
    }
}
