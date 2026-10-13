<?php

namespace App\Http\Middleware;

use App\Support\PortalSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Member portal pages: the PIN was entered in the last 15 minutes and the link is still current.
 */
class EnsurePortalUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $access = PortalSession::identified();
        if ($access === null) {
            return redirect()->route('portal.start');
        }
        if (PortalSession::partyId() !== $access->party_id || $access->isLocked() || PortalSession::isIdle()
            || ! hash_equals((string) session('portal.pin_fp'), hash('sha256', $access->pin_hash))) {
            PortalSession::lock();

            return redirect()->route('portal.pin');
        }
        if ($access->must_change_pin && ! $request->routeIs('portal.pin.change', 'portal.pin.update')) {
            return redirect()->route('portal.pin.change');
        }
        PortalSession::touch();
        $request->attributes->set('portalAccess', $access);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
