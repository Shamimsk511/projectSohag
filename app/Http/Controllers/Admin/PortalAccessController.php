<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\PortalAccessService;
use App\Support\QrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Office side of the member portal. The office PIN is flashed once (`portal_pin`) and never stored in clear.
 */
class PortalAccessController extends Controller
{
    public function __construct(private readonly PortalAccessService $portal) {}

    public function store(Party $party): RedirectResponse
    {
        Gate::authorize('managePortal', $party);
        ['pin' => $pin] = $this->portal->issue($party, $this->actor());

        return $this->back($party, $pin, __('app.portal.issued'));
    }

    public function resetPin(Party $party): RedirectResponse
    {
        Gate::authorize('managePortal', $party);
        $pin = $this->portal->resetPin($party->portalAccess()->firstOrFail(), $this->actor());

        return $this->back($party, $pin, __('app.portal.pin_reset'));
    }

    public function regenerate(Party $party): RedirectResponse
    {
        Gate::authorize('managePortal', $party);
        $pin = $this->portal->regenerate($party->portalAccess()->firstOrFail(), $this->actor());

        return $this->back($party, $pin, __('app.portal.regenerated'));
    }

    public function card(Party $party): View
    {
        Gate::authorize('managePortal', $party);
        $link = $this->portal->link($party->portalAccess()->firstOrFail());

        return view('admin.parties.portal-card', ['party' => $party, 'qr' => QrCode::svg($link)]);
    }

    private function back(Party $party, string $pin, string $status): RedirectResponse
    {
        return redirect()->route('admin.parties.show', $party)->with('status', $status)->with('portal_pin', $pin);
    }
}
