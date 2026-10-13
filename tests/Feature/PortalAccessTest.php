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
            $this->assertSame('wrong', $service->verifyPin($access, $pin === '000000' ? '111111' : '000000'));
        }
        $this->assertSame('locked', $service->verifyPin($access, $pin === '000000' ? '111111' : '000000'));
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
        $seen = [];

        $this->actingAs($this->manager)->post(route('admin.parties.portal.store', $party))
            ->assertRedirect(route('admin.parties.show', $party))
            ->assertSessionHas('portal_pin');
        $access = PortalAccess::query()->sole();
        $seen[] = $access->token_hash;
        $seen[] = $access->pin_hash;
        $seen[] = decrypt($access->token_encrypted);
        $this->actingAs($this->manager)->post(route('admin.parties.portal.reset-pin', $party))->assertSessionHas('portal_pin');
        $seen[] = $access->refresh()->pin_hash;
        $this->actingAs($this->manager)->post(route('admin.parties.portal.regenerate', $party))->assertSessionHas('portal_pin');
        $access->refresh();
        array_push($seen, $access->token_hash, $access->pin_hash, decrypt($access->token_encrypted));

        $logs = AuditLog::query()->where('action', 'like', 'portal.%')->orderBy('id')->get();
        $this->assertSame(['portal.issued', 'portal.pin_reset', 'portal.regenerated'], $logs->pluck('action')->all());
        foreach ($logs as $log) {
            $json = (string) json_encode([$log->before, $log->after]);
            foreach ($seen as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
        }
    }

    public function test_issuing_twice_reports_the_error_on_the_member_page(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($this->manager)->post(route('admin.parties.portal.store', $party));
        $this->actingAs($this->manager)->post(route('admin.parties.portal.store', $party))
            ->assertSessionHasErrors('portal');
        $this->actingAs($this->manager)->get(route('admin.parties.show', $party))->assertOk();
    }

    public function test_member_page_shows_the_portal_card_and_pin_once(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($this->manager)->get(route('admin.parties.show', $party))->assertOk()
            ->assertSee(__('app.portal.issue'));

        $this->actingAs($this->manager)->post(route('admin.parties.portal.store', $party));
        $pin = session('portal_pin');
        $this->actingAs($this->manager)->get(route('admin.parties.show', $party))->assertOk()->assertSee($pin);
        $this->actingAs($this->manager)->get(route('admin.parties.show', $party))->assertOk()->assertDontSee('data-portal-pin', false);
    }

    public function test_print_card_has_a_qr_and_never_the_pin(): void
    {
        $party = Party::factory()->create();
        $this->actingAs($this->manager)->post(route('admin.parties.portal.store', $party));

        $this->actingAs($this->manager)->get(route('admin.parties.portal.card', $party))->assertOk()
            ->assertSee('<svg', false)
            ->assertSee($party->party_code)
            ->assertDontSee('data-portal-pin', false)
            ->assertDontSee(__('app.portal.office_pin'));
    }

    public function test_accountant_cannot_manage_portal_access(): void
    {
        $party = Party::factory()->create();

        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->post(route('admin.parties.portal.store', $party))->assertForbidden();
    }
}
