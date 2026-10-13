<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalAccess;
use App\Services\PortalAccessService;
use App\Support\Numerals;
use App\Support\PortalSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PinController extends Controller
{
    public function __construct(private readonly PortalAccessService $portal) {}

    public function show(): View|RedirectResponse
    {
        $access = PortalSession::identified();
        if ($access === null) {
            return redirect()->route('portal.start');
        }

        return view($access->isLocked() ? 'portal.locked' : 'portal.pin', ['access' => $access]);
    }

    public function check(Request $request): RedirectResponse
    {
        $access = PortalSession::identified();
        if ($access === null) {
            return redirect()->route('portal.start');
        }
        $validator = Validator::make($request->all(), ['pin' => ['required', 'string', 'max:12']]);
        if ($validator->fails()) {
            return redirect()->route('portal.pin')->withErrors($validator);
        }

        $result = $this->portal->verifyPin($access, $request->string('pin')->value());
        if ($result === 'ok') {
            PortalSession::unlock($access->refresh());

            return redirect()->route('portal.home');
        }
        if ($result === 'locked') {
            return redirect()->route('portal.pin');
        }

        return redirect()->route('portal.pin')->withErrors(['pin' => __('app.portal.wrong_pin', [
            'left' => Numerals::localize((string) (PortalAccessService::MAX_TRIES - $access->failed_attempts)),
        ])]);
    }

    public function edit(): View
    {
        return view('portal.change-pin');
    }

    public function update(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), ['pin' => ['required', 'string', 'max:12', 'confirmed']]);
        if ($validator->fails()) {
            return redirect()->route('portal.pin.change')->withErrors($validator);
        }
        /** @var PortalAccess $access */
        $access = $request->attributes->get('portalAccess');
        try {
            $this->portal->changePin($access, $request->string('pin')->value());
        } catch (ValidationException $e) {
            return redirect()->route('portal.pin.change')->withErrors($e->errors());
        }
        session()->put('portal.pin_fp', hash('sha256', $access->refresh()->pin_hash));

        return redirect()->route('portal.home');
    }

    public function lock(): RedirectResponse
    {
        PortalSession::lock();

        return redirect()->route('portal.pin');
    }
}
