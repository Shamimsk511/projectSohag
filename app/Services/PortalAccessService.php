<?php

namespace App\Services;

use App\Models\Party;
use App\Models\PortalAccess;
use App\Models\User;
use App\Support\Numerals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Member portal links and PINs. The only writer of `portal_accesses`.
 */
class PortalAccessService
{
    public const int MAX_TRIES = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{access: PortalAccess, pin: string}
     */
    public function issue(Party $party, User $actor): array
    {
        return DB::transaction(function () use ($party, $actor): array {
            if (PortalAccess::query()->where('party_id', $party->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['portal' => __('app.portal.already_issued')]);
            }
            $token = Str::random(40);
            $pin = $this->officePin();
            $access = PortalAccess::query()->create([
                'party_id' => $party->id,
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => encrypt($token),
                'pin_hash' => Hash::make($pin),
                'must_change_pin' => true,
                'failed_attempts' => 0,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);
            $this->audit->created('portal.issued', $access, $actor);

            return ['access' => $access, 'pin' => $pin];
        });
    }

    /**
     * New office PIN; clears the lock and the wrong-try count. The member must choose a new PIN again.
     */
    public function resetPin(PortalAccess $access, User $actor): string
    {
        return DB::transaction(function () use ($access, $actor): string {
            $access = $this->lock($access);
            $pin = $this->officePin();
            $access->update(['pin_hash' => Hash::make($pin), 'must_change_pin' => true, 'failed_attempts' => 0, 'locked_at' => null]);
            $this->audit->updated('portal.pin_reset', $access, $actor);

            return $pin;
        });
    }

    /**
     * New secret link (old link and every remembered phone stop working) and a new office PIN.
     */
    public function regenerate(PortalAccess $access, User $actor): string
    {
        return DB::transaction(function () use ($access, $actor): string {
            $access = $this->lock($access);
            $token = Str::random(40);
            $pin = $this->officePin();
            $access->update([
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => encrypt($token),
                'pin_hash' => Hash::make($pin),
                'must_change_pin' => true,
                'failed_attempts' => 0,
                'locked_at' => null,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);
            $this->audit->updated('portal.regenerated', $access, $actor);

            return $pin;
        });
    }

    public function link(PortalAccess $access): string
    {
        return route('portal.link', decrypt($access->token_encrypted));
    }

    public function findByToken(string $token): ?PortalAccess
    {
        return PortalAccess::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Never rehash pin_hash here: PortalSession stores a fingerprint of it, so a rehash would lock every open session.
     *
     * @return 'ok'|'wrong'|'locked'
     */
    public function verifyPin(PortalAccess $access, string $pin): string
    {
        return DB::transaction(function () use ($access, $pin): string {
            $fresh = $this->lock($access);
            if ($fresh->isLocked()) {
                return 'locked';
            }
            if (Hash::check(Numerals::toAscii(trim($pin)), $fresh->pin_hash)) {
                $fresh->update(['failed_attempts' => 0, 'last_login_at' => now()]);
                $access->setRawAttributes($fresh->getAttributes(), true);

                return 'ok';
            }
            $failed = $fresh->failed_attempts + 1;
            $fresh->update(['failed_attempts' => $failed, 'locked_at' => $failed >= self::MAX_TRIES ? now() : null]);
            $access->setRawAttributes($fresh->getAttributes(), true);

            return $failed >= self::MAX_TRIES ? 'locked' : 'wrong';
        });
    }

    public function changePin(PortalAccess $access, string $newPin): void
    {
        $pin = Numerals::toAscii(trim($newPin));
        if (preg_match('/^\d{4,6}$/', $pin) !== 1) {
            throw ValidationException::withMessages(['pin' => __('app.portal.pin_rules')]);
        }
        if (Hash::check($pin, $access->pin_hash)) {
            throw ValidationException::withMessages(['pin' => __('app.portal.pin_same')]);
        }
        $access->update(['pin_hash' => Hash::make($pin), 'must_change_pin' => false]);
    }

    private function officePin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function lock(PortalAccess $access): PortalAccess
    {
        return PortalAccess::query()->whereKey($access->id)->lockForUpdate()->firstOrFail();
    }
}
