<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\PortalAccessService;
use App\Support\PortalSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Entry from the printed QR / WhatsApp link, and the page shown to browsers that have neither.
 */
class AccessController extends Controller
{
    public function open(string $token, PortalAccessService $portal): RedirectResponse
    {
        $access = $portal->findByToken($token);
        abort_if($access === null, 404);
        PortalSession::forget();
        PortalSession::identify($access);

        return redirect()->route('portal.pin');
    }

    public function start(): View
    {
        return view('portal.start');
    }
}
