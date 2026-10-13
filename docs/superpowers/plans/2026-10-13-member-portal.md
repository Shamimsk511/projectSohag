# Member Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A member opens a personal secret link (printed QR or WhatsApp), unlocks with a PIN and sees their own accounts, statements and receipts, read-only, as an installable PWA.

**Architecture:** `portal_accesses` holds one secret link per party (sha256 for lookup, `encrypt()`ed copy for reprint) and a bcrypt PIN. `PortalAccessService` is the only writer (issue, reset PIN, new link, verify/change PIN). Members never touch the staff `auth` guard: a small session state (`portal.*` keys) plus an encrypted 1-year device cookie and middleware `portal.unlocked` gate the member routes under `/portal`; every query is scoped to the session's party. Office tools sit on a "Member portal" card on the admin member page.

**Tech Stack:** Laravel 13, Blade, `bacon/bacon-qr-code` (approved 2026-10-13), vanilla JS Vite entry `resources/js/portal.js`, hand-written service worker, PHPUnit.

**Spec:** docs/superpowers/specs/2026-10-13-member-portal-design.md

## Global Constraints

- Read-only for members: no member write except changing their own PIN. No interest/profit wording; reuse neutral labels already in `app.accounts.*` / `app.parties.progress.*`.
- Token = `Str::random(40)`; lookup by `hash('sha256', $token)`; reprint via `decrypt($token_encrypted)`. Office PIN = 6 random digits (`random_int`), member PIN 4-6 digits (Bengali digits normalised), never equal to the office PIN; stored with `Hash::make`.
- Secrets never in audit rows or logs: model `$hidden = ['token_hash', 'token_encrypted', 'pin_hash']` (AuditLogger stores hidden columns as `[redacted]`).
- 5 wrong PINs -> `locked_at` set; only office "Reset PIN" unlocks. PIN post throttled `throttle:10,1,portal-pin`; link open throttled `throttle:30,1,portal-link`. Unknown token -> 404.
- Device cookie `portal_device` (Laravel encrypts cookies in the web group): JSON `{a: access_id, h: token_hash}`, 1 year (525600 min), httpOnly, SameSite lax, `secure` = `config('session.secure')`. A new link (new token_hash) invalidates every device.
- Unlocked portal session ends after 15 minutes idle (`portal.seen_at` in session).
- Session keys: `portal.access_id` (identified, not unlocked), `portal.party_id` (unlocked), `portal.token_hash`, `portal.seen_at`, `portal.must_change`. Member pages never read `auth()`; staff sessions get no portal access (no portal keys) and portal state grants nothing under `/admin` or `/field`.
- Every member query scoped by `portal.party_id`; foreign account/transaction -> 404.
- Office tools permission: `parties.manage` (manager, admin) via `PartyPolicy::managePortal`. Writes audited: `portal.issued`, `portal.pin_reset`, `portal.regenerated`.
- Portal pages: own layout `layouts.portal` (field-like, `<meta name="robots" content="noindex">`), own Vite entry `resources/js/portal.js` (Bootstrap CSS + icons + fonts only), manifest `public/portal.webmanifest` (scope/start_url `/portal`), service worker `public/portal-sw.js` caching only the shell + `/portal/offline`, never member HTML.
- UI strings in `lang/{en,bn}/app.php` group `portal`; en/bn parity.
- Do not commit (working tree holds other uncommitted work). Done = `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse` 0 errors, `npm run build`.

---

### Task 1: Portal access data, service, QR and office tools

**Files:**
- Create: `database/migrations/<ts>_create_portal_accesses_table.php`, `app/Models/PortalAccess.php`, `app/Services/PortalAccessService.php`, `app/Support/QrCode.php`, `app/Http/Controllers/Admin/PortalAccessController.php`, `resources/views/admin/parties/_portal.blade.php`, `resources/views/admin/parties/portal-card.blade.php`
- Modify: `composer.json`/`composer.lock` (via composer), `app/Models/Party.php` (relation), `app/Policies/PartyPolicy.php`, `app/Http/Controllers/Admin/PartyController.php` (show), `resources/views/admin/parties/show.blade.php`, `routes/web.php`, `lang/en/app.php`, `lang/bn/app.php`, `tests/Feature/PermissionMatrixTest.php`
- Test: `tests/Feature/PortalAccessTest.php`

**Interfaces:**
- Produces:
  - `PortalAccess` model: `party_id`, `token_hash`, `token_encrypted`, `pin_hash`, `must_change_pin` (bool), `failed_attempts` (int), `locked_at` (?Carbon), `issued_by`, `issued_at`, `last_login_at` (?Carbon); `party()` BelongsTo; `isLocked(): bool`. `Party::portalAccess(): HasOne`.
  - `PortalAccessService::issue(Party $party, User $actor): array{access: PortalAccess, pin: string}` (throws ValidationException `app.portal.already_issued` if one exists)
  - `PortalAccessService::resetPin(PortalAccess $access, User $actor): string` (new office PIN)
  - `PortalAccessService::regenerate(PortalAccess $access, User $actor): string` (new link + new office PIN; returns PIN)
  - `PortalAccessService::link(PortalAccess $access): string` (absolute URL of `portal.link`)
  - `PortalAccessService::findByToken(string $token): ?PortalAccess`
  - `PortalAccessService::verifyPin(PortalAccess $access, string $pin): string` returns `'ok'|'wrong'|'locked'`
  - `PortalAccessService::changePin(PortalAccess $access, string $newPin): void` (ValidationException on rule failure, key `pin`)
  - `QrCode::svg(string $text, int $size = 240): string`
  - Routes (admin group): `admin.parties.portal.store` POST `parties/{party}/portal`, `admin.parties.portal.reset-pin` POST `parties/{party}/portal/reset-pin`, `admin.parties.portal.regenerate` POST `parties/{party}/portal/regenerate`, `admin.parties.portal.card` GET `parties/{party}/portal/card`
  - Route `portal.link` GET `/m/{token}` is defined in Task 2; Task 1 must register a placeholder so `route('portal.link', ...)` resolves — define the final route line now (Step 6) pointing to `App\Http\Controllers\Portal\AccessController@open`, created as a stub that `abort(404)`s; Task 2 fills it.

- [ ] **Step 1: Install QR library**

Run: `composer require bacon/bacon-qr-code --no-interaction`
Expected: installs (3.x). Do not touch other dependencies.

- [ ] **Step 2: Failing tests** — `tests/Feature/PortalAccessTest.php`

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\PortalAccess;
use App\Models\User;
use App\Services\PortalAccessService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = User::factory()->withRole(Role::Manager)->create();
    }

    public function test_issue_creates_a_secret_link_and_a_six_digit_office_pin(): void
    {
        $party = Party::factory()->create();
        $service = app(PortalAccessService::class);

        ['access' => $access, 'pin' => $pin] = $service->issue($party, $this->manager);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertTrue(Hash::check($pin, $access->pin_hash));
        $this->assertTrue($access->must_change_pin);
        $token = decrypt($access->token_encrypted);
        $this->assertSame(40, strlen($token));
        $this->assertSame(hash('sha256', $token), $access->token_hash);
        $this->assertSame(route('portal.link', $token), $service->link($access));
        $this->assertTrue($service->findByToken($token)?->is($access));
        $this->assertNull($service->findByToken(str_repeat('x', 40)));

        $this->expectException(ValidationException::class);
        $service->issue($party, $this->manager);
    }

    public function test_wrong_pins_lock_the_access_and_reset_unlocks_it(): void
    {
        $service = app(PortalAccessService::class);
        ['access' => $access, 'pin' => $pin] = $service->issue(Party::factory()->create(), $this->manager);

        foreach (range(1, 4) as $try) {
            $this->assertSame('wrong', $service->verifyPin($access, '000000' === $pin ? '111111' : '000000'));
        }
        $this->assertSame('locked', $service->verifyPin($access, '000000' === $pin ? '111111' : '000000'));
        $this->assertSame('locked', $service->verifyPin($access->refresh(), $pin)); // correct PIN no longer works
        $this->assertTrue($access->isLocked());

        $newPin = $service->resetPin($access, $this->manager);
        $this->assertSame('ok', $service->verifyPin($access->refresh(), $newPin));
        $this->assertSame(0, $access->refresh()->failed_attempts);
        $this->assertNotNull($access->last_login_at);
    }

    public function test_member_pin_change_rules(): void
    {
        $service = app(PortalAccessService::class);
        ['access' => $access, 'pin' => $pin] = $service->issue(Party::factory()->create(), $this->manager);

        foreach (['12', '1234567', 'abcd', $pin] as $bad) {
            try {
                $service->changePin($access, $bad);
                $this->fail("PIN {$bad} was accepted.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('pin', $exception->errors());
            }
        }

        $service->changePin($access, '৪৫৬৭'); // Bengali digits
        $this->assertTrue(Hash::check('4567', $access->refresh()->pin_hash));
        $this->assertFalse($access->must_change_pin);
    }

    public function test_regenerate_kills_the_old_link(): void
    {
        $service = app(PortalAccessService::class);
        ['access' => $access] = $service->issue(Party::factory()->create(), $this->manager);
        $oldToken = decrypt($access->token_encrypted);

        $service->regenerate($access, $this->manager);

        $this->assertNull($service->findByToken($oldToken));
        $this->assertNotNull($service->findByToken(decrypt($access->refresh()->token_encrypted)));
        $this->assertTrue($access->must_change_pin);
    }

    public function test_office_actions_are_audited_without_secrets(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($this->manager)->post(route('admin.parties.portal.store', $party))
            ->assertRedirect(route('admin.parties.show', $party))
            ->assertSessionHas('portal_pin');
        $this->actingAs($this->manager)->post(route('admin.parties.portal.reset-pin', $party))->assertSessionHas('portal_pin');
        $this->actingAs($this->manager)->post(route('admin.parties.portal.regenerate', $party))->assertSessionHas('portal_pin');

        $logs = AuditLog::query()->where('action', 'like', 'portal.%')->orderBy('id')->get();
        $this->assertSame(['portal.issued', 'portal.pin_reset', 'portal.regenerated'], $logs->pluck('action')->all());
        $access = PortalAccess::query()->sole();
        foreach ($logs as $log) {
            $json = json_encode([$log->before, $log->after]);
            $this->assertStringNotContainsString($access->token_hash, (string) $json);
            $this->assertStringNotContainsString($access->pin_hash, (string) $json);
            $this->assertStringNotContainsString(decrypt($access->token_encrypted), (string) $json);
        }
    }

    public function test_member_page_shows_the_portal_card_and_pin_once(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($this->manager)->get(route('admin.parties.show', $party))->assertOk()
            ->assertSee(__('app.portal.issue'));

        $this->actingAs($this->manager)->post(route('admin.parties.portal.store', $party));
        $pin = session('portal_pin');
        $this->actingAs($this->manager)->get(route('admin.parties.show', $party))->assertOk()->assertSee($pin);
        $this->actingAs($this->manager)->get(route('admin.parties.show', $party))->assertOk()->assertDontSee($pin);
    }

    public function test_print_card_has_a_qr_and_never_the_pin(): void
    {
        $party = Party::factory()->create();
        $this->actingAs($this->manager)->post(route('admin.parties.portal.store', $party));
        $pin = session('portal_pin');

        $this->actingAs($this->manager)->get(route('admin.parties.portal.card', $party))->assertOk()
            ->assertSee('<svg', false)
            ->assertSee($party->party_code)
            ->assertDontSee($pin);
    }

    public function test_accountant_cannot_manage_portal_access(): void
    {
        $party = Party::factory()->create();

        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->post(route('admin.parties.portal.store', $party))->assertForbidden();
    }
}
```

(Note on the card PIN check: a 6-digit PIN could theoretically appear inside SVG path data; if that flakes, assert the card has no element `data-portal-pin` and that the PIN label key `app.portal.office_pin` is absent instead.)

Run: `php artisan test --compact tests/Feature/PortalAccessTest.php` -> FAIL (classes missing).

- [ ] **Step 3: Migration, model, relation**

`php artisan make:migration create_portal_accesses_table --no-interaction`, content:

```php
    /**
     * One secret member-portal link per party, with the member's PIN. Secrets are hashed or encrypted.
     */
    public function up(): void
    {
        Schema::create('portal_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->unique()->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->string('pin_hash');
            $table->boolean('must_change_pin')->default(true);
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_accesses');
    }
```

`app/Models/PortalAccess.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's secret portal link and PIN. Written only by PortalAccessService.
 *
 * @property int $id
 * @property int $party_id
 * @property string $token_hash
 * @property string $token_encrypted
 * @property string $pin_hash
 * @property bool $must_change_pin
 * @property int $failed_attempts
 * @property Carbon|null $locked_at
 * @property int $issued_by
 * @property Carbon $issued_at
 * @property Carbon|null $last_login_at
 */
#[Fillable(['party_id', 'token_hash', 'token_encrypted', 'pin_hash', 'must_change_pin', 'failed_attempts', 'locked_at', 'issued_by', 'issued_at', 'last_login_at'])]
class PortalAccess extends Model
{
    /** Never in arrays, JSON or audit rows (AuditLogger redacts hidden columns). */
    protected $hidden = ['token_hash', 'token_encrypted', 'pin_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'must_change_pin' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_at' => 'datetime',
            'issued_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
```

`app/Models/Party.php`: add (with `HasOne` import, matching the file's relation docblock style):

```php
    /**
     * @return HasOne<PortalAccess, $this>
     */
    public function portalAccess(): HasOne
    {
        return $this->hasOne(PortalAccess::class);
    }
```

- [ ] **Step 4: QR helper** — `app/Support/QrCode.php`

```php
<?php

namespace App\Support;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR codes as inline SVG (no GD/Imagick), sharp when printed.
 */
final class QrCode
{
    public static function svg(string $text, int $size = 240): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd())))->writeString($text);

        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg); // inline in HTML: drop the XML prolog
    }
}
```

(Check the installed bacon version's class names in `vendor/bacon/bacon-qr-code/src` only if this does not compile — read just the class you need.)

- [ ] **Step 5: Service** — `app/Services/PortalAccessService.php`

```php
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
```

(Check `App\Support\Numerals` has `toAscii`; the handoff notes say "`App\Support\Numerals` for digit localize/toAscii". If the method name differs, use the existing one.)

Note for `regenerate`/`resetPin`: callers holding the old `$access` instance should `refresh()`; tests do.

- [ ] **Step 6: Policy, controller, routes, stub link route**

`app/Policies/PartyPolicy.php`:

```php
    /**
     * Issue, print, share or reset the member's portal link and PIN.
     */
    public function managePortal(User $user, Party $party): bool
    {
        return $user->can(Permission::ManageParties->value);
    }
```

`app/Http/Controllers/Admin/PortalAccessController.php`:

```php
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
```

`routes/web.php` admin group (near the other `parties/{party}/...` lines), import the controller:

```php
        Route::post('parties/{party}/portal', [PortalAccessController::class, 'store'])->name('parties.portal.store');
        Route::post('parties/{party}/portal/reset-pin', [PortalAccessController::class, 'resetPin'])->name('parties.portal.reset-pin');
        Route::post('parties/{party}/portal/regenerate', [PortalAccessController::class, 'regenerate'])->name('parties.portal.regenerate');
        Route::get('parties/{party}/portal/card', [PortalAccessController::class, 'card'])->name('parties.portal.card');
```

Outside every auth group (public, after the `field/offline` route), the member link route that Task 2 implements:

```php
Route::get('m/{token}', [\App\Http\Controllers\Portal\AccessController::class, 'open'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1,portal-link')->name('portal.link');
```

Create `app/Http/Controllers/Portal/AccessController.php` stub now (Task 2 replaces the body):

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

class AccessController extends Controller
{
    public function open(string $token): Response
    {
        abort(404);
    }
}
```

`tests/Feature/PermissionMatrixTest.php`: `MATRIX` add the four admin routes with `[self::MANAGER]`; add `'portal.link'` to `OPEN`.

- [ ] **Step 7: Lang** (group `portal` before `'pin' => [` in both files)

en:

```php
    'portal' => [
        'title' => 'Member portal',
        'issue' => 'Create portal link',
        'issued' => 'Portal link created. Give the member the PIN shown below (tell them or write it on a separate slip).',
        'already_issued' => 'This member already has a portal link.',
        'office_pin' => 'Office PIN (shown once)',
        'pin_once_hint' => 'Never write the PIN on the printed QR card.',
        'not_issued' => 'No portal link yet.',
        'status_waiting' => 'Waiting for the member to set their own PIN',
        'status_active' => 'Member has set their PIN',
        'status_locked' => 'Locked after wrong PINs',
        'last_login' => 'Last opened',
        'print_card' => 'Print QR card',
        'share' => 'Share link on WhatsApp',
        'reset_pin' => 'Reset PIN',
        'reset_confirm' => 'Make a new office PIN? The member must choose a new PIN again.',
        'pin_reset' => 'New office PIN made.',
        'regenerate' => 'New link',
        'regenerate_confirm' => 'Make a new link? The old link and the QR card stop working on every phone.',
        'regenerated' => 'New link and office PIN made. Print a new card.',
        'card_scan' => 'Scan to see your accounts',
        'card_pin_hint' => 'Ask the office for your PIN.',
        'pin_rules' => 'Use 4 to 6 digits.',
        'pin_same' => 'Choose a PIN different from the office PIN.',
    ],
```

bn:

```php
    'portal' => [
        'title' => 'সদস্য পোর্টাল',
        'issue' => 'পোর্টাল লিংক তৈরি করুন',
        'issued' => 'পোর্টাল লিংক তৈরি হয়েছে। নিচের পিন সদস্যকে দিন (মুখে বলুন বা আলাদা কাগজে লিখে দিন)।',
        'already_issued' => 'এই সদস্যের পোর্টাল লিংক আগে থেকেই আছে।',
        'office_pin' => 'অফিস পিন (একবারই দেখানো হবে)',
        'pin_once_hint' => 'ছাপানো কিউআর কার্ডে কখনো পিন লিখবেন না।',
        'not_issued' => 'এখনো পোর্টাল লিংক নেই।',
        'status_waiting' => 'সদস্য এখনো নিজের পিন দেননি',
        'status_active' => 'সদস্য নিজের পিন দিয়েছেন',
        'status_locked' => 'ভুল পিনের কারণে বন্ধ',
        'last_login' => 'শেষ খোলা হয়েছে',
        'print_card' => 'কিউআর কার্ড প্রিন্ট করুন',
        'share' => 'হোয়াটসঅ্যাপে লিংক পাঠান',
        'reset_pin' => 'পিন রিসেট',
        'reset_confirm' => 'নতুন অফিস পিন তৈরি করবেন? সদস্যকে আবার নিজের পিন দিতে হবে।',
        'pin_reset' => 'নতুন অফিস পিন তৈরি হয়েছে।',
        'regenerate' => 'নতুন লিংক',
        'regenerate_confirm' => 'নতুন লিংক তৈরি করবেন? পুরনো লিংক ও কিউআর কার্ড কোনো ফোনেই আর কাজ করবে না।',
        'regenerated' => 'নতুন লিংক ও অফিস পিন তৈরি হয়েছে। নতুন কার্ড প্রিন্ট করুন।',
        'card_scan' => 'আপনার হিসাব দেখতে স্ক্যান করুন',
        'card_pin_hint' => 'পিন অফিস থেকে জেনে নিন।',
        'pin_rules' => '৪ থেকে ৬ অঙ্ক দিন।',
        'pin_same' => 'অফিস পিন থেকে আলাদা পিন দিন।',
    ],
```

- [ ] **Step 8: Member page card + print card**

`PartyController::show()`: add `'portalAccess'` to the `load([...])` list (`'portalAccess'`) and to the view data `'canManagePortal' => Gate::allows('managePortal', $party)`.

`resources/views/admin/parties/_portal.blade.php`:

```blade
{{-- Member portal card on the member page. Expects $party (portalAccess loaded), $canManagePortal. --}}
@php($access = $party->portalAccess)
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title mb-0"><i class="bi bi-phone me-1" aria-hidden="true"></i> {{ __('app.portal.title') }}</h3></div>
    <div class="card-body">
        @if (session('portal_pin'))
            <div class="alert alert-warning">
                <div class="small">{{ __('app.portal.office_pin') }}</div>
                <div class="display-6 fw-bold font-monospace" data-portal-pin>{{ session('portal_pin') }}</div>
                <div class="small">{{ __('app.portal.pin_once_hint') }}</div>
            </div>
        @endif
        @if (! $access)
            <p class="text-body-secondary">{{ __('app.portal.not_issued') }}</p>
            <form method="POST" action="{{ route('admin.parties.portal.store', $party) }}">
                @csrf
                <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg" aria-hidden="true"></i> {{ __('app.portal.issue') }}</button>
            </form>
        @else
            <p class="mb-2">
                @if ($access->isLocked())
                    <span class="badge text-bg-danger">{{ __('app.portal.status_locked') }}</span>
                @elseif ($access->must_change_pin)
                    <span class="badge text-bg-warning">{{ __('app.portal.status_waiting') }}</span>
                @else
                    <span class="badge text-bg-success">{{ __('app.portal.status_active') }}</span>
                @endif
                @if ($access->last_login_at)
                    <span class="small text-body-secondary ms-2">{{ __('app.portal.last_login') }}: {{ \App\Support\Numerals::localize($access->last_login_at->copy()->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i')) }}</span>
                @endif
            </p>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="{{ route('admin.parties.portal.card', $party) }}" target="_blank" rel="noopener"><i class="bi bi-qr-code" aria-hidden="true"></i> {{ __('app.portal.print_card') }}</a>
                <x-whatsapp.portal :party="$party" :access="$access" />
                <form method="POST" action="{{ route('admin.parties.portal.reset-pin', $party) }}" class="js-confirm"
                      data-confirm="{{ json_encode(['title' => __('app.assignments.confirm_title'), 'text' => __('app.portal.reset_confirm'), 'confirm' => __('app.portal.reset_pin'), 'cancel' => __('app.cancel')]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-warning">{{ __('app.portal.reset_pin') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.parties.portal.regenerate', $party) }}" class="js-confirm"
                      data-confirm="{{ json_encode(['title' => __('app.assignments.confirm_title'), 'text' => __('app.portal.regenerate_confirm'), 'confirm' => __('app.portal.regenerate'), 'cancel' => __('app.cancel')]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">{{ __('app.portal.regenerate') }}</button>
                </form>
            </div>
        @endif
    </div>
</div>
```

`<x-whatsapp.portal>` is created in Task 4; until then, create `resources/views/components/whatsapp/portal.blade.php` as an empty component file containing only `@props(['party', 'access'])` so the page renders.

In `resources/views/admin/parties/show.blade.php`, include it in the right-hand column near the assignment card (find where `_assignment` is included; add after it): `@if ($canManagePortal) @include('admin.parties._portal') @endif`. The `.js-confirm` forms already work on the party page (parties.js / confirm.js); verify the page's JS entry includes `initConfirmForms`.

`resources/views/admin/parties/portal-card.blade.php` (printable, field layout like the schedule print sheet):

```blade
@extends('layouts.field')

@section('title', __('app.portal.title'))
@section('hide_logout', true)

@section('content')
    <div class="card mx-auto text-center" style="max-width: 22rem">
        <div class="card-body">
            <div class="h5 mb-1">{{ config('app.name') }}</div>
            <div class="text-body-secondary mb-3">{{ __('app.portal.title') }}</div>
            <div class="mx-auto" style="width: 240px">{!! $qr !!}</div>
            <div class="fw-semibold mt-3">{{ __('app.portal.card_scan') }}</div>
            <div class="fs-5 mt-2">{{ $party->name }}</div>
            <div class="text-body-secondary">{{ $party->party_code }}</div>
            <div class="small mt-2">{{ __('app.portal.card_pin_hint') }}</div>
        </div>
    </div>
    <div class="text-center mt-3 d-print-none">
        <button type="button" class="btn btn-primary btn-lg js-print"><i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.receipts.print') }}</button>
    </div>
@endsection
```

(`{!! $qr !!}` is safe: the SVG is generated by bacon from our own URL, no user input.)

- [ ] **Step 9: Run** — `php artisan test --compact tests/Feature/PortalAccessTest.php tests/Feature/PermissionMatrixTest.php tests/Feature/LangFilesTest.php tests/Feature/DomainInvariantsTest.php tests/Feature/QueryCountTest.php` -> PASS; `vendor/bin/phpstan analyse --no-progress` -> 0.

---

### Task 2: Member sign-in (link, PIN, change PIN, device cookie, idle lock)

**Files:**
- Create: `app/Http/Middleware/EnsurePortalUnlocked.php`, `app/Support/PortalSession.php`, `app/Http/Controllers/Portal/PinController.php`, `resources/views/layouts/portal.blade.php`, `resources/views/portal/pin.blade.php`, `resources/views/portal/change-pin.blade.php`, `resources/views/portal/start.blade.php`, `resources/views/portal/locked.blade.php`, `resources/views/portal/home.blade.php` (placeholder, filled in Task 3), `resources/js/portal.js`, `resources/css/portal.css`
- Modify: `app/Http/Controllers/Portal/AccessController.php`, `bootstrap/app.php` (alias), `routes/web.php`, `vite.config.js`, `lang/{en,bn}/app.php` (`portal` group additions), `tests/Feature/PermissionMatrixTest.php` (OPEN entries)
- Test: `tests/Feature/PortalSignInTest.php`

**Interfaces:**
- Consumes (Task 1): `PortalAccessService::findByToken/verifyPin/changePin`, `PortalAccess`, `PortalAccessService::MAX_TRIES`.
- Produces:
  - `PortalSession` (final, static helpers over `session()` + cookie): `identify(PortalAccess $access): void` (sets `portal.access_id`, `portal.token_hash`, queues device cookie), `identified(): ?PortalAccess` (from session or device cookie; null when hash mismatch/locked state handled by caller), `unlock(PortalAccess $access): void` (sets `portal.party_id`, `portal.seen_at`, `portal.must_change`), `partyId(): ?int`, `touch(): void`, `isIdle(): bool` (> 15 min), `lock(): void` (forget `portal.party_id`, `portal.seen_at`, `portal.must_change`; keep identification), `forget(): void` (all keys + cookie), constants `COOKIE = 'portal_device'`, `IDLE_MINUTES = 15`.
  - Middleware alias `portal.unlocked`: requires unlocked + not idle + access still has `portal.token_hash` and not locked; else `PortalSession::lock()` and redirect to `portal.pin` (or `portal.start` when not identified); when `portal.must_change` -> redirect to `portal.pin.change` unless already on it. Touches `seen_at`.
  - Routes (public web group, no `auth`): `portal.start` GET `/portal/start`; `portal.pin` GET `/portal/pin`; `portal.pin.check` POST `/portal/pin` (throttle `10,1,portal-pin`); `portal.pin.change` GET + `portal.pin.update` POST `/portal/pin/change` (both `portal.unlocked`); `portal.lock` POST `/portal/lock`; `portal.home` GET `/portal` (`portal.unlocked`). Task 3 adds `portal.accounts.show`, `portal.receipts.show`; Task 4 adds `portal.offline`.

- [ ] **Step 1: Failing tests** — `tests/Feature/PortalSignInTest.php`

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Party;
use App\Models\User;
use App\Services\PortalAccessService;
use App\Support\PortalSession;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortalSignInTest extends TestCase
{
    use RefreshDatabase;

    private PortalAccessService $portal;

    private Party $party;

    private string $token;

    private string $officePin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->portal = app(PortalAccessService::class);
        $this->party = Party::factory()->create(['name' => 'Rahima Begum']);
        ['access' => $access, 'pin' => $this->officePin] = $this->portal->issue($this->party, User::factory()->withRole(Role::Manager)->create());
        $this->token = decrypt($access->token_encrypted);
    }

    public function test_first_open_asks_office_pin_then_forces_a_new_pin(): void
    {
        $this->get(route('portal.link', $this->token))->assertRedirect(route('portal.pin'))->assertCookie(PortalSession::COOKIE);
        $this->get(route('portal.pin'))->assertOk()->assertSee('Rahima Begum');
        $this->post(route('portal.pin.check'), ['pin' => $this->officePin])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertRedirect(route('portal.pin.change'));

        $this->post(route('portal.pin.update'), ['pin' => $this->officePin, 'pin_confirmation' => $this->officePin])->assertSessionHasErrors('pin');
        $this->post(route('portal.pin.update'), ['pin' => '4321', 'pin_confirmation' => '4321'])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk();
    }

    public function test_unknown_or_malformed_link_is_404(): void
    {
        $this->get('/m/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/m/short')->assertNotFound();
    }

    public function test_wrong_pins_lock_and_show_the_locked_page(): void
    {
        $this->get(route('portal.link', $this->token));
        $wrong = $this->officePin === '000000' ? '111111' : '000000';
        foreach (range(1, 4) as $try) {
            $this->post(route('portal.pin.check'), ['pin' => $wrong])->assertSessionHasErrors('pin');
        }
        $this->post(route('portal.pin.check'), ['pin' => $wrong])->assertRedirect(route('portal.pin'));
        $this->get(route('portal.pin'))->assertOk()->assertSee(__('app.portal.locked'));
        $this->post(route('portal.pin.check'), ['pin' => $this->officePin])->assertRedirect(route('portal.pin'));
        $this->get(route('portal.home'))->assertRedirect(route('portal.pin'));
    }

    public function test_idle_session_asks_for_the_pin_again_and_the_device_cookie_identifies_the_member(): void
    {
        $this->signIn();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->get(route('portal.home'))->assertRedirect(route('portal.pin'));
        $this->get(route('portal.pin'))->assertOk()->assertSee('Rahima Begum'); // still identified

        // A new browser session with only the device cookie: PIN screen, not the start page.
        $cookie = $this->portalCookie();
        $this->flushSession();
        $this->withCookie(PortalSession::COOKIE, $cookie)->get(route('portal.home'))->assertRedirect(route('portal.pin'));
        $this->withCookie(PortalSession::COOKIE, $cookie)->get(route('portal.pin'))->assertOk()->assertSee('Rahima Begum');
    }

    public function test_new_link_kills_remembered_devices_and_sessions(): void
    {
        $this->signIn();
        $cookie = $this->portalCookie();
        $this->portal->regenerate($this->party->portalAccess()->firstOrFail(), User::factory()->withRole(Role::Manager)->create());

        $this->get(route('portal.home'))->assertRedirect(route('portal.start'));
        $this->flushSession();
        $this->withCookie(PortalSession::COOKIE, $cookie)->get(route('portal.pin'))->assertRedirect(route('portal.start'));
    }

    public function test_lock_button_ends_the_unlocked_session_but_keeps_the_device(): void
    {
        $this->signIn();
        $this->post(route('portal.lock'))->assertRedirect(route('portal.pin'));
        $this->get(route('portal.home'))->assertRedirect(route('portal.pin'));
    }

    public function test_start_page_without_link_or_cookie(): void
    {
        $this->get(route('portal.home'))->assertRedirect(route('portal.start'));
        $this->get(route('portal.start'))->assertOk()->assertSee(__('app.portal.start_hint'));
    }

    public function test_portal_session_grants_nothing_on_staff_pages_and_staff_get_no_portal(): void
    {
        $this->signIn();
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/field')->assertRedirect(route('login'));

        $this->flushSession();
        $this->actingAs(User::factory()->withRole(Role::Admin)->create())->get(route('portal.home'))->assertRedirect(route('portal.start'));
    }

    private function signIn(): void
    {
        $this->get(route('portal.link', $this->token));
        $this->post(route('portal.pin.check'), ['pin' => $this->officePin]);
        $this->post(route('portal.pin.update'), ['pin' => '4321', 'pin_confirmation' => '4321']);
    }

    private function portalCookie(): string
    {
        $access = $this->party->portalAccess()->firstOrFail();

        return json_encode(['a' => $access->id, 'h' => $access->token_hash], JSON_THROW_ON_ERROR);
    }
}
```

(`withCookie` in tests sends the plain value; Laravel's test client encrypts cookies for the EncryptCookies middleware. If the device-cookie assertions fail only because of encryption in tests, use `$this->withUnencryptedCookie` vs `withCookie` per the framework docs — check with `search-docs` "testing cookies".)

Run -> FAIL.

- [ ] **Step 2: `app/Support/PortalSession.php`**

```php
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
            'portal.must_change' => $access->must_change_pin,
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
        session()->forget(['portal.party_id', 'portal.seen_at', 'portal.must_change']);
    }

    public static function forget(): void
    {
        session()->forget(['portal.access_id', 'portal.token_hash', 'portal.party_id', 'portal.seen_at', 'portal.must_change']);
        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
```

- [ ] **Step 3: Middleware** — `app/Http/Middleware/EnsurePortalUnlocked.php`

```php
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
        if (PortalSession::partyId() !== $access->party_id || $access->isLocked() || PortalSession::isIdle()) {
            PortalSession::lock();

            return redirect()->route('portal.pin');
        }
        if (session('portal.must_change') && ! $request->routeIs('portal.pin.change', 'portal.pin.update')) {
            return redirect()->route('portal.pin.change');
        }
        PortalSession::touch();
        $request->attributes->set('portalAccess', $access);

        return $next($request);
    }
}
```

`bootstrap/app.php` in `withMiddleware`: `$middleware->alias(['portal.unlocked' => \App\Http\Middleware\EnsurePortalUnlocked::class]);` (use an import).

- [ ] **Step 4: Controllers**

`app/Http/Controllers/Portal/AccessController.php` (replace stub):

```php
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
```

`app/Http/Controllers/Portal/PinController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalAccess;
use App\Services\PortalAccessService;
use App\Support\Numerals;
use App\Support\PortalSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $request->validate(['pin' => ['required', 'string', 'max:12']]);

        $result = $this->portal->verifyPin($access, $request->string('pin')->value());
        if ($result === 'ok') {
            PortalSession::unlock($access->refresh());

            return redirect()->route('portal.home');
        }
        if ($result === 'locked') {
            return redirect()->route('portal.pin');
        }

        return back()->withErrors(['pin' => __('app.portal.wrong_pin', [
            'left' => Numerals::localize((string) (PortalAccessService::MAX_TRIES - $access->failed_attempts)),
        ])]);
    }

    public function edit(): View
    {
        return view('portal.change-pin');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate(['pin' => ['required', 'string', 'max:12', 'confirmed']]);
        /** @var PortalAccess $access */
        $access = $request->attributes->get('portalAccess');
        $this->portal->changePin($access, $request->string('pin')->value());
        session()->put('portal.must_change', false);

        return redirect()->route('portal.home');
    }

    public function lock(): RedirectResponse
    {
        PortalSession::lock();

        return redirect()->route('portal.pin');
    }
}
```

(`confirmed` compares raw strings; Bengali vs ASCII digits typed differently would mismatch — acceptable; message says they did not match.)

- [ ] **Step 5: Routes** (public; after the `m/{token}` line)

```php
Route::prefix('portal')->name('portal.')->group(function (): void {
    Route::get('start', [\App\Http\Controllers\Portal\AccessController::class, 'start'])->name('start');
    Route::get('pin', [PinController::class, 'show'])->name('pin');
    Route::post('pin', [PinController::class, 'check'])->middleware('throttle:10,1,portal-pin')->name('pin.check');
    Route::post('lock', [PinController::class, 'lock'])->name('lock');
    Route::middleware('portal.unlocked')->group(function (): void {
        Route::get('/', [PortalController::class, 'home'])->name('home');
        Route::get('pin/change', [PinController::class, 'edit'])->name('pin.change');
        Route::post('pin/change', [PinController::class, 'update'])->name('pin.update');
    });
});
```

Import `App\Http\Controllers\Portal\PinController` and `App\Http\Controllers\Portal\PortalController`; create `PortalController` with `home()` returning `view('portal.home')` for now (Task 3 fills it). Add every `portal.*` route name to `PermissionMatrixTest::OPEN` (they are member routes outside staff auth; their own tests cover access).

- [ ] **Step 6: Layout, views, assets**

`resources/views/layouts/portal.blade.php`:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#198754">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ __('app.portal.title') }}">
    <link rel="manifest" href="/portal.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <title>@hasSection('title')@yield('title') | @endif{{ config('app.name') }}</title>
    @vite('resources/js/portal.js')
</head>
<body class="bg-body-tertiary" @if (! Vite::isRunningHot() && $swVersion = Vite::manifestHash()) data-sw="/portal-sw.js?v={{ $swVersion }}" @endif>
    <header class="navbar bg-body border-bottom px-3 d-print-none">
        <span class="navbar-brand mb-0 h1">{{ config('app.name') }}</span>
        @if (\App\Support\PortalSession::partyId())
            <form method="POST" action="{{ route('portal.lock') }}">
                @csrf
                <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('app.portal.lock') }}</button>
            </form>
        @endif
    </header>
    <main class="container py-3" style="max-width: 40rem">
        @yield('content')
    </main>
</body>
</html>
```

`resources/views/portal/pin.blade.php`:

```blade
@extends('layouts.portal')

@section('title', __('app.portal.enter_pin'))

@section('content')
    <div class="card mx-auto" style="max-width: 22rem">
        <div class="card-body text-center">
            <i class="bi bi-shield-lock display-5 text-success" aria-hidden="true"></i>
            <h1 class="h4 mt-2">{{ __('app.portal.enter_pin') }}</h1>
            <div class="text-body-secondary mb-3">{{ $access->party->name }}</div>
            <form method="POST" action="{{ route('portal.pin.check') }}">
                @csrf
                <input type="password" name="pin" inputmode="numeric" autocomplete="off" maxlength="12" required autofocus
                       class="form-control form-control-lg text-center @error('pin') is-invalid @enderror" aria-label="{{ __('app.portal.enter_pin') }}">
                @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button type="submit" class="btn btn-success btn-lg w-100 mt-3">{{ __('app.portal.open') }}</button>
            </form>
            <p class="small text-body-secondary mt-3 mb-0">{{ __('app.portal.forgot_pin') }}</p>
        </div>
    </div>
@endsection
```

`resources/views/portal/change-pin.blade.php`:

```blade
@extends('layouts.portal')

@section('title', __('app.portal.new_pin'))

@section('content')
    <div class="card mx-auto" style="max-width: 22rem">
        <div class="card-body">
            <h1 class="h4 text-center">{{ __('app.portal.new_pin') }}</h1>
            <p class="text-body-secondary text-center">{{ __('app.portal.new_pin_hint') }}</p>
            <form method="POST" action="{{ route('portal.pin.update') }}">
                @csrf
                <label class="form-label" for="pin">{{ __('app.portal.new_pin') }}</label>
                <input type="password" id="pin" name="pin" inputmode="numeric" autocomplete="new-password" maxlength="12" required
                       class="form-control form-control-lg text-center @error('pin') is-invalid @enderror">
                @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <label class="form-label mt-3" for="pin_confirmation">{{ __('app.portal.new_pin_again') }}</label>
                <input type="password" id="pin_confirmation" name="pin_confirmation" inputmode="numeric" autocomplete="new-password" maxlength="12" required
                       class="form-control form-control-lg text-center">
                <button type="submit" class="btn btn-success btn-lg w-100 mt-3">{{ __('app.save') }}</button>
            </form>
        </div>
    </div>
@endsection
```

`resources/views/portal/start.blade.php`:

```blade
@extends('layouts.portal')

@section('content')
    <div class="text-center py-5">
        <i class="bi bi-qr-code-scan display-4 text-success" aria-hidden="true"></i>
        <h1 class="h4 mt-3">{{ __('app.portal.title') }}</h1>
        <p class="text-body-secondary">{{ __('app.portal.start_hint') }}</p>
    </div>
@endsection
```

`resources/views/portal/locked.blade.php`:

```blade
@extends('layouts.portal')

@section('content')
    <div class="alert alert-danger text-center my-5" role="alert">
        <i class="bi bi-lock-fill fs-3 d-block mb-2" aria-hidden="true"></i>
        {{ __('app.portal.locked') }}
    </div>
@endsection
```

`resources/views/portal/home.blade.php` (placeholder): `@extends('layouts.portal') @section('content') <h1 class="h4">{{ __('app.portal.title') }}</h1> @endsection`.

Lang additions to `portal` (en / bn):

```php
        'enter_pin' => 'Enter your PIN',            // 'আপনার পিন দিন'
        'open' => 'Open',                            // 'খুলুন'
        'forgot_pin' => 'Forgot your PIN? Ask the office to reset it.', // 'পিন ভুলে গেলে অফিসে বলুন, নতুন পিন দেবে।'
        'wrong_pin' => 'Wrong PIN. :left tries left.', // 'ভুল পিন। আর :left বার চেষ্টা করা যাবে।'
        'locked' => 'Too many wrong PINs. This link is locked. Ask the office to reset your PIN.', // 'অনেকবার ভুল পিন দেওয়া হয়েছে। লিংকটি বন্ধ। অফিসে বলে পিন রিসেট করিয়ে নিন।'
        'new_pin' => 'Choose your own PIN',          // 'নিজের পিন দিন'
        'new_pin_hint' => '4 to 6 digits. Do not share it.', // '৪ থেকে ৬ অঙ্ক। কাউকে বলবেন না।'
        'new_pin_again' => 'Same PIN again',         // 'একই পিন আবার দিন'
        'start_hint' => 'Open the link the office gave you (QR card or WhatsApp).', // 'অফিস থেকে দেওয়া লিংক খুলুন (কিউআর কার্ড বা হোয়াটসঅ্যাপ)।'
        'lock' => 'Lock',                            // 'বন্ধ করুন'
```

(Write en and bn as real arrays in each file; the comments above only pair the translations.)

`resources/css/portal.css`:

```css
/* Member portal overrides loaded after Bootstrap. Mobile-first. */
:root {
    --bs-body-font-family: "Source Sans 3", "Noto Sans Bengali", system-ui, -apple-system, "Segoe UI", sans-serif;
}

.btn,
.form-control {
    min-height: 48px;
}
```

`resources/js/portal.js`:

```js
/**
 * Member portal entry (read-only pages). Bootstrap CSS, icons and fonts only.
 */
import './fonts.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import '../css/portal.css';

document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('.js-print')) {
        window.print();
    }
});

const serviceWorkerUrl = document.body.dataset.sw;
if (serviceWorkerUrl && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(serviceWorkerUrl, { scope: '/portal' }).catch(() => {});
    });
}
```

`vite.config.js`: add `'resources/js/portal.js'` to `input`.

- [ ] **Step 7: Run** — `php artisan test --compact tests/Feature/PortalSignInTest.php tests/Feature/PermissionMatrixTest.php tests/Feature/LangFilesTest.php tests/Feature/ViteEntriesTest.php` -> PASS; `npm run build`; PHPStan 0.

---

### Task 3: Member screens (accounts, statement, receipt)

**Files:**
- Create: `resources/views/admin/parties/_progress_card.blade.php` (extracted), `resources/views/portal/statement.blade.php`, `resources/views/portal/receipt.blade.php`
- Modify: `app/Http/Controllers/Portal/PortalController.php`, `resources/views/portal/home.blade.php`, `resources/views/admin/parties/show.blade.php`, `resources/views/admin/accounts/_statement_table.blade.php`, `routes/web.php`, `lang/{en,bn}/app.php`
- Test: `tests/Feature/PortalScreensTest.php`

**Interfaces:**
- Consumes: `ReportService::memberAccounts(EloquentCollection $accounts, Carbon $today)`, `LedgerService::statement(Account, ?Carbon, ?Carbon)`, `AccountStatementRequest` rules (from/to), `PortalSession`, middleware `portal.unlocked` (request attribute `portalAccess`).
- Produces: routes `portal.accounts.show` GET `/portal/accounts/{account}` (query from/to), `portal.receipts.show` GET `/portal/receipts/{transaction}`; partial `admin.parties._progress_card` with variables `$account`, `$line`, `$statementUrl` (string), `$accountUrl` (?string); `_statement_table` gains optional `$receiptRoute` (default `'admin.transactions.receipt'`).

- [ ] **Step 1: Failing tests** — `tests/Feature/PortalScreensTest.php`

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PortalAccessService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortalScreensTest extends TestCase
{
    use RefreshDatabase;

    private Account $loan;

    private Account $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $party = Party::factory()->create();
        $this->loan = Account::factory()->loan('1200')->create(['party_id' => $party->id]);
        Transaction::factory()->of($this->loan, TransactionType::LoanDisbursement, '1000')->create();
        Transaction::factory()->of($this->loan, TransactionType::LoanCollection, '200')->create(['receipt_no' => 'R-202610-00001']);
        $this->foreign = Account::factory()->loan('500')->create();

        $portal = app(PortalAccessService::class);
        ['access' => $access, 'pin' => $pin] = $portal->issue($party, User::factory()->withRole(Role::Manager)->create());
        $this->get(route('portal.link', decrypt($access->token_encrypted)));
        $this->post(route('portal.pin.check'), ['pin' => $pin]);
        $this->post(route('portal.pin.update'), ['pin' => '4321', 'pin_confirmation' => '4321']);
    }

    public function test_home_shows_own_accounts_with_remaining(): void
    {
        $this->get(route('portal.home'))->assertOk()
            ->assertSee($this->loan->account_number)
            ->assertSee(Money::fromTaka('1000')->format()) // remaining 1200 - 200
            ->assertDontSee($this->foreign->account_number)
            ->assertDontSee(route('admin.accounts.show', $this->loan), false);
    }

    public function test_statement_and_receipt_are_scoped_to_the_member(): void
    {
        $receipt = Transaction::query()->where('receipt_no', 'R-202610-00001')->sole();

        $this->get(route('portal.accounts.show', $this->loan))->assertOk()
            ->assertSee('R-202610-00001')
            ->assertSee(route('portal.receipts.show', $receipt), false)
            ->assertDontSee('admin/transactions', false);
        $this->get(route('portal.receipts.show', $receipt))->assertOk()->assertSee(Money::fromTaka('200')->format());

        $this->get(route('portal.accounts.show', $this->foreign))->assertNotFound();
        $foreignReceipt = Transaction::factory()->of($this->foreign, TransactionType::LoanCollection, '50')->create(['receipt_no' => 'R-202610-00002']);
        $this->get(route('portal.receipts.show', $foreignReceipt))->assertNotFound();
    }

    public function test_statement_period_filter_validates(): void
    {
        $this->get(route('portal.accounts.show', [$this->loan, 'from' => '2026-10-12', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
    }
}
```

Run -> FAIL.

- [ ] **Step 2: Extract the progress card** — move the `<div class="col-md-6 col-xl-4"> ... </div>` block (the per-account card inside `@foreach ($party->accounts as $account)` in `admin/parties/show.blade.php`, including the `@php` that builds `$rows`) into `resources/views/admin/parties/_progress_card.blade.php`. In the partial:
  - define at top `@php($date = fn (?\Illuminate\Support\Carbon $value): ?string => $value ? \App\Support\Numerals::localize($value->format('d/m/Y')) : null)` and `@php($n = fn (int $value): string => \App\Support\Numerals::localize((string) $value))` if `$n` is defined in show.blade.php (check its definition and copy it);
  - replace `route('admin.accounts.show', $account)` with `$accountUrl` (render the account-number as a link only `@if ($accountUrl)`, else plain text; the footer "account page" link only `@if ($accountUrl)`);
  - replace `route('admin.accounts.statement', $account)` with `$statementUrl`.
  In show.blade.php the loop becomes:

```blade
            @foreach ($party->accounts as $account)
                @include('admin.parties._progress_card', [
                    'line' => $progress[$account->id],
                    'statementUrl' => route('admin.accounts.statement', $account),
                    'accountUrl' => route('admin.accounts.show', $account),
                ])
            @endforeach
```

Run `php artisan test --compact tests/Feature/MemberAccountProgressTest.php tests/Feature/QueryCountTest.php` -> still PASS (pure refactor).

- [ ] **Step 3: Statement table receipt route** — in `admin/accounts/_statement_table.blade.php` add at the top of the `@php` block `$receiptRoute ??= 'admin.transactions.receipt';` and use `route($receiptRoute, $transaction)` at the receipt link (line ~53). `$reversible` already short-circuits on `$actions` before calling `auth()->user()`, so `actions => false` is safe without a staff user.

- [ ] **Step 4: Controller + routes**

`app/Http/Controllers/Portal/PortalController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountStatementRequest;
use App\Models\Account;
use App\Models\Party;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Support\PortalSession;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Read-only member pages. Everything is scoped to the party of the unlocked portal session.
 */
class PortalController extends Controller
{
    public function home(ReportService $reports): View
    {
        $party = $this->party();
        $party->load(['accounts' => fn ($accounts) => $accounts->orderBy('closed_at')->orderBy('id')]);

        return view('portal.home', [
            'party' => $party,
            'progress' => $reports->memberAccounts($party->accounts, Carbon::parse(today(config('app.display_timezone'))->toDateString())),
        ]);
    }

    public function statement(AccountStatementRequest $request, Account $account, LedgerService $ledger): View
    {
        abort_unless($account->party_id === PortalSession::partyId(), 404);

        return view('portal.statement', [
            'account' => $account,
            'statement' => $ledger->statement($account, $request->periodFrom(), $request->periodTo()),
        ]);
    }

    public function receipt(Transaction $transaction): View
    {
        abort_unless($transaction->receipt_no !== null && $transaction->party_id === PortalSession::partyId(), 404);
        $transaction->load(['account', 'party', 'fieldOfficer', 'createdBy', 'reversal']);

        return view('portal.receipt', ['transaction' => $transaction]);
    }

    private function party(): Party
    {
        return Party::query()->findOrFail(PortalSession::partyId());
    }
}
```

`AccountStatementRequest::authorize()` checks a staff `view` permission; for the portal it must not. Create `app/Http/Requests/Portal/PortalStatementRequest.php` extending `AccountStatementRequest` with `public function authorize(): bool { return true; }` (scoping is done in the controller) and use it in `statement()` instead. Check `AccountStatementRequest` for `periodFrom()/periodTo()` names first.

Routes inside the `portal.unlocked` group:

```php
        Route::get('accounts/{account}', [PortalController::class, 'statement'])->name('accounts.show');
        Route::get('receipts/{transaction}', [PortalController::class, 'receipt'])->name('receipts.show');
```

Add both to `PermissionMatrixTest::OPEN`.

- [ ] **Step 5: Views**

`resources/views/portal/home.blade.php`:

```blade
@extends('layouts.portal')

@section('title', __('app.portal.title'))

@section('content')
    <h1 class="h4 mb-1">{{ $party->name }}</h1>
    <div class="text-body-secondary mb-3">{{ $party->party_code }}</div>
    <div class="row g-3">
        @forelse ($party->accounts as $account)
            @include('admin.parties._progress_card', [
                'line' => $progress[$account->id],
                'statementUrl' => route('portal.accounts.show', $account),
                'accountUrl' => null,
            ])
        @empty
            <p class="text-body-secondary">{{ __('app.portal.no_accounts') }}</p>
        @endforelse
    </div>
@endsection
```

(The partial's column classes `col-md-6 col-xl-4` render one per row on phones; fine.)

`resources/views/portal/statement.blade.php`:

```blade
@extends('layouts.portal')

@section('title', $account->account_number)

@section('content')
    <a href="{{ route('portal.home') }}" class="btn btn-link px-0 d-print-none"><i class="bi bi-arrow-left" aria-hidden="true"></i> {{ __('app.portal.back') }}</a>
    <h1 class="h4">{{ $account->account_number }} · {{ __('app.accounts.types.'.$account->account_type->value) }}</h1>
    <form method="GET" class="row g-2 align-items-end mb-3 d-print-none">
        <div class="col-6"><x-form.input name="from" type="date" :label="__('app.accounts.statement_from')" :value="request('from')" /></div>
        <div class="col-6"><x-form.input name="to" type="date" :label="__('app.accounts.statement_to')" :value="request('to')" /></div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-success">{{ __('app.dash.show') }}</button>
            <button type="button" class="btn btn-outline-secondary js-print"><i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.receipts.print') }}</button>
        </div>
    </form>
    @include('admin.accounts._statement_table', ['actions' => false, 'receiptRoute' => 'portal.receipts.show'])
@endsection
```

(Check the existing lang keys for the from/to labels used on `admin/accounts/statement.blade.php` and reuse those exact keys.)

`resources/views/portal/receipt.blade.php`: same markup as the receipt card in `receipts/show.blade.php` (the `<div class="card">…</div>` block and the `$date` helper), on `layouts.portal`, with a Print button and a back link to `route('portal.accounts.show', $transaction->account_id)`. To avoid duplicating the card, first extract that card into `resources/views/receipts/_card.blade.php` (expects `$transaction`, computes `$date` and `$receivedBy` itself) and include it from both `receipts/show.blade.php` and `portal/receipt.blade.php`. Run `tests/Feature` receipt tests after the extraction.

Lang `portal`: `no_accounts` ('No accounts yet.' / 'এখনো কোনো হিসাব নেই।'), `back` ('Back' / 'ফিরে যান').

- [ ] **Step 6: Run** — `php artisan test --compact tests/Feature/PortalScreensTest.php tests/Feature/PortalSignInTest.php tests/Feature/MemberAccountProgressTest.php tests/Feature/QueryCountTest.php tests/Feature/PermissionMatrixTest.php tests/Feature/FieldCollectionScreensTest.php` (receipts) -> PASS; PHPStan 0.

---

### Task 4: PWA shell, WhatsApp portal link, docs, browser check

**Files:**
- Create: `public/portal.webmanifest`, `public/portal-sw.js`, `resources/views/portal/offline.blade.php`
- Modify: `routes/web.php`, `resources/views/components/whatsapp/portal.blade.php` (real), `app/Enums/WhatsAppKind.php`, `app/Http/Requests/LogWhatsAppRequest.php`, `app/Http/Controllers/WhatsAppLogController.php`, `app/Services/WhatsAppService.php`, `lang/{en,bn}/app.php`, `docs/ARCHITECTURE.md`, `docs/PWA.md`, `docs/ROADMAP.md`, `tests/Feature/PermissionMatrixTest.php`
- Test: `tests/Feature/PortalPwaTest.php`, `tests/Feature/WhatsAppShareTest.php` (append)

**Interfaces:**
- Consumes: `PortalAccessService::link`, `PartyPolicy::managePortal`, WhatsApp button component, `WhatsAppService::record`.
- Produces: route `portal.offline` GET `/portal/offline` (public); `WhatsAppKind::Portal = 'portal'`; `WhatsAppService::portalText(Party $party, string $link): string`; receipt text gains a portal line when the party has access.

- [ ] **Step 1: Failing tests**

`tests/Feature/PortalPwaTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortalPwaTest extends TestCase
{
    public function test_manifest_scopes_the_portal(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('portal.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/portal', $manifest['start_url']);
        $this->assertSame('/portal', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
    }

    public function test_offline_page_is_public_and_carries_no_member_data(): void
    {
        $this->withoutVite();

        $this->get(route('portal.offline'))->assertOk()
            ->assertSee(__('app.portal.offline'))
            ->assertDontSee(route('portal.lock'), false);
    }

    public function test_service_worker_never_caches_portal_pages(): void
    {
        $sw = (string) file_get_contents(public_path('portal-sw.js'));

        $this->assertStringContainsString("'/portal/offline'", $sw);
        $this->assertStringContainsString("'portal-shell-'", $sw);
    }
}
```

Append to `tests/Feature/WhatsAppShareTest.php`:

```php
    public function test_office_shares_the_portal_link_and_receipts_mention_it(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $schedule = $this->schedule($this->officer);
        $party = $schedule->party;
        $this->actingAs($manager)->post(route('admin.parties.portal.store', $party));
        $link = app(\App\Services\PortalAccessService::class)->link($party->portalAccess()->firstOrFail());

        $this->actingAs($manager)->get(route('admin.parties.show', $party))->assertOk()
            ->assertSee(rawurlencode($link), false);
        $this->actingAs($manager)->postJson(route('admin.whatsapp.log'), ['kind' => 'portal', 'party_id' => $party->id])->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->postJson(route('admin.whatsapp.log'), ['kind' => 'portal', 'party_id' => $party->id])->assertForbidden();

        $receipt = Transaction::factory()->of($schedule->account, TransactionType::LoanCollection, '100')
            ->create(['receipt_no' => 'R-202610-00031', 'field_officer_id' => $this->officer->id]);
        $this->assertStringContainsString($link, app(WhatsAppService::class)->receiptText($receipt->load(['party', 'account'])));
    }
```

(Use imports instead of FQCNs.)

Run -> FAIL.

- [ ] **Step 2: Manifest + service worker + offline route**

`public/portal.webmanifest`:

```json
{
    "name": "সৃজনশীল সদস্য",
    "short_name": "সদস্য",
    "lang": "bn",
    "start_url": "/portal",
    "scope": "/portal",
    "display": "standalone",
    "orientation": "portrait",
    "theme_color": "#198754",
    "background_color": "#ffffff",
    "icons": [
        { "src": "/icons/icon-192.png", "sizes": "192x192", "type": "image/png" },
        { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png" },
        { "src": "/icons/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
    ]
}
```

`public/portal-sw.js`: copy `public/field-sw.js` verbatim, then change only:
- header comment: "Member portal service worker (scope /portal). Same strategy as field-sw.js: shell assets cache-first, navigations network-first with /portal/offline fallback, member HTML never cached."
- `const CACHE_PREFIX = 'portal-shell-';`
- `const OFFLINE_URL = '/portal/offline';`
- `const MANIFEST_URL = '/portal.webmanifest';`
Leave every other line identical (so both workers behave the same).

Route (public, outside the `portal.unlocked` group but inside the `portal.` prefix group): `Route::view('offline', 'portal.offline')->name('offline');`. Add `portal.offline` to `PermissionMatrixTest::OPEN`.

`resources/views/portal/offline.blade.php`:

```blade
@extends('layouts.portal')

@section('content')
    <div class="alert alert-warning text-center my-5" role="status">
        <i class="bi bi-wifi-off fs-3 d-block mb-2" aria-hidden="true"></i>
        {{ __('app.portal.offline') }}
    </div>
@endsection
```

The layout shows the Lock button only when `PortalSession::partyId()` is set; the service worker fetches the offline page with `credentials: 'omit'`, so the cached copy has no session. Lang `portal.offline`: 'No internet. Your accounts show again when you are online.' / 'ইন্টারনেট নেই। অনলাইনে এলে আবার হিসাব দেখা যাবে।'

- [ ] **Step 3: WhatsApp portal share + receipt line**

`WhatsAppKind`: add `case Portal = 'portal';`.

`LogWhatsAppRequest::rules()`: `'party_id' => ['required_if:kind,portal', 'nullable', 'integer', 'exists:parties,id']` and change `transaction_id` to `required_if:kind,receipt,notice`.

`WhatsAppLogController`: add a branch before the reminder branch:

```php
        if ($kind === WhatsAppKind::Portal) {
            $party = Party::query()->with('portalAccess')->findOrFail($request->integer('party_id'));
            Gate::authorize('managePortal', $party);
            abort_if($party->portalAccess === null, 422);
        } elseif ($kind === WhatsAppKind::Reminder) {
```

(adapt to the controller's current structure; keep the existing phone-validity 422 and reversal/office checks for the other kinds.)

`WhatsAppService`:

```php
    public function portalText(Party $party, string $link): string
    {
        return __('app.whatsapp.portal', ['name' => $party->name, 'link' => $link, 'app' => config('app.name')]);
    }
```

and in `receiptText()` append the portal line when the party has access (inject `PortalAccessService` via the constructor):

```php
        $access = $transaction->party?->portalAccess;
        $text = __('app.whatsapp.receipt', [...]); // existing call
        return $access ? $text."\n".__('app.whatsapp.portal_line', ['link' => $this->portal->link($access)]) : $text;
```

(`portalAccess` is a single relation on a single receipt page: one extra query, acceptable; on list pages receipts are not rendered.)

Lang `whatsapp` additions:
- en: `'portal' => "Assalamu alaikum :name,\nsee your accounts any time: :link\nAsk the office for your PIN.\n— :app"`, `'portal_line' => 'Your accounts: :link'`, `'share_portal' => 'Send portal link on WhatsApp'`
- bn: `'portal' => "আসসালামু আলাইকুম :name,\nযেকোনো সময় আপনার হিসাব দেখুন: :link\nপিন অফিস থেকে জেনে নিন।\n— :app"`, `'portal_line' => 'আপনার হিসাব: :link'`, `'share_portal' => 'হোয়াটসঅ্যাপে পোর্টাল লিংক পাঠান'`

`resources/views/components/whatsapp/portal.blade.php`:

```blade
{{-- Portal link to the member over WhatsApp (office only). --}}
@props(['party', 'access'])
@inject('whatsapp', 'App\Services\WhatsAppService')
@inject('portal', 'App\Services\PortalAccessService')
@can('managePortal', $party)
    @php($href = \App\Support\WhatsApp::link($party->phone, $whatsapp->portalText($party, $portal->link($access))))
    @if ($href)
        <x-whatsapp.button :href="$href" :log="['kind' => 'portal', 'party_id' => $party->id]" :label="__('app.whatsapp.share_portal')" {{ $attributes }} />
    @endif
@endcan
```

(Match how the other whatsapp components forward attributes.)

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/PortalPwaTest.php tests/Feature/WhatsAppShareTest.php tests/Feature/PortalAccessTest.php tests/Feature/PermissionMatrixTest.php tests/Feature/LangFilesTest.php` -> PASS; `npm run build`.

- [ ] **Step 5: Docs**

`docs/ARCHITECTURE.md` Tables, add: `- \`portal_accesses\`: party_id (unique), token_hash (sha256, unique), token_encrypted, pin_hash, must_change_pin, failed_attempts, locked_at, issued_by, issued_at, last_login_at (member portal link + PIN; written only by PortalAccessService)`. Layout section, add: `- Member portal: \`/m/{token}\` + \`/portal/*\` (public web routes, own session keys + \`portal_device\` cookie + \`portal.unlocked\` middleware, never the staff guard), layout \`layouts.portal\`, Vite entry \`resources/js/portal.js\`, \`public/portal-sw.js\`.`

`docs/PWA.md`: add a short section "Member portal PWA": manifest `/portal.webmanifest` scope `/portal`, `portal-sw.js` (same strategy, offline page `/portal/offline`, no member HTML cached), sign-in by secret link + office PIN -> own PIN, 1-year device cookie, 15-min idle, 5 wrong -> locked until office reset.

`docs/ROADMAP.md`: tick `- [ ] Member portal` and append handoff: `- 2026-10-13: Member portal: run \`php artisan migrate\` and \`composer install\` (bacon/bacon-qr-code). \`PortalAccessService\` (issue/resetPin/regenerate/verifyPin/changePin/link), office card on member page (issue -> PIN shown once, print QR card, WhatsApp link, reset PIN, new link), member routes \`/m/{token}\` + \`/portal\` (\`PortalSession\`, \`portal.unlocked\`), home = progress cards (shared partial \`admin.parties._progress_card\`), statement (shared table, \`receiptRoute\`), receipt (shared \`receipts._card\`), PWA manifest + \`portal-sw.js\`. Receipts' WhatsApp text adds the portal link when issued.`

- [ ] **Step 6: Browser check** (Playwright MCP; scratch SQLite on localhost as in earlier plans; `npm run build`; serve on 8123 in background; stop with TaskStop). Manager: member page -> Create portal link -> PIN shown once -> Print QR card (SVG renders, no PIN) -> WhatsApp button href decodes to the portal text with link (block `https://wa.me/**`). Member (fresh context, 390px): open the link -> PIN page with name -> office PIN -> forced new PIN -> home cards -> statement (date filter, print button) -> receipt. Lock -> PIN asked; 5 wrong -> locked page; manager Reset PIN -> works again. New link -> old link 404s, remembered context redirected to start. Manifest/installability: no console errors, SW registered with scope `/portal`, offline navigation shows the offline page. No horizontal scroll at 390px.

- [ ] **Step 7: Full gate** — `php artisan test --compact && vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --no-progress && npm run build` -> all pass.
