<?php

namespace App\Support;

use App\Models\PortalAccess;
use Illuminate\Support\Facades\Cookie;

/**
 * Member portal state: who this browser belongs to (session or 1-year device cookie) and whether the PIN was
 * entered recently. Members never use the staff auth guard.
 */
final class PortalSession
{
    public const string COOKIE = 'portal_device';

    public const int IDLE_MINUTES = 15;

    public static function identify(PortalAccess $access): void
    {
        session()->put(['portal.access_id' => $access->id, 'portal.token_hash' => $access->token_hash]);
        Cookie::queue(Cookie::make(
            self::COOKIE,
            json_encode(['a' => $access->id, 'h' => $access->token_hash], JSON_THROW_ON_ERROR),
            525600,
            secure: config('session.secure'),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /**
     * The member this browser belongs to, if the link is still the current one.
     */
    public static function identified(): ?PortalAccess
    {
        $id = session('portal.access_id');
        $hash = session('portal.token_hash');
        if ($id === null) {
            $cookie = json_decode((string) request()->cookie(self::COOKIE), true);
            $id = is_array($cookie) ? ($cookie['a'] ?? null) : null;
            $hash = is_array($cookie) ? ($cookie['h'] ?? null) : null;
        }
        if (! is_int($id) || ! is_string($hash)) {
            return null;
        }
        $access = PortalAccess::query()->with('party')->find($id);
        if ($access === null || ! hash_equals($access->token_hash, $hash)) {
            self::forget();

            return null;
        }
        session()->put(['portal.access_id' => $access->id, 'portal.token_hash' => $access->token_hash]);

        return $access;
    }

    public static function unlock(PortalAccess $access): void
    {
        session()->regenerate();
        session()->put([
            'portal.access_id' => $access->id,
            'portal.token_hash' => $access->token_hash,
            'portal.party_id' => $access->party_id,
            'portal.seen_at' => now()->getTimestamp(),
            'portal.pin_fp' => hash('sha256', $access->pin_hash),
        ]);
    }

    public static function partyId(): ?int
    {
        $id = session('portal.party_id');

        return is_int($id) ? $id : null;
    }

    public static function isIdle(): bool
    {
        return now()->getTimestamp() - (int) session('portal.seen_at', 0) > self::IDLE_MINUTES * 60;
    }

    public static function touch(): void
    {
        session()->put('portal.seen_at', now()->getTimestamp());
    }

    public static function lock(): void
    {
        session()->forget(['portal.party_id', 'portal.seen_at', 'portal.pin_fp']);
    }

    public static function forget(): void
    {
        session()->forget(['portal.access_id', 'portal.token_hash', 'portal.party_id', 'portal.seen_at', 'portal.pin_fp']);
        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
