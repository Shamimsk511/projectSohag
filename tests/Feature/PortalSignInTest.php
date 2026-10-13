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

    public function test_office_pin_reset_ends_a_live_session_and_own_change_keeps_it(): void
    {
        $this->signIn();
        $this->get(route('portal.home'))->assertOk();
        $newOfficePin = $this->portal->resetPin($this->party->portalAccess()->firstOrFail(), User::factory()->withRole(Role::Manager)->create());
        $this->get(route('portal.home'))->assertRedirect(route('portal.pin'));

        $this->post(route('portal.pin.check'), ['pin' => $newOfficePin])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertRedirect(route('portal.pin.change'));
        $this->post(route('portal.pin.update'), ['pin' => '5678', 'pin_confirmation' => '5678'])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk();
    }

    public function test_wrong_pin_without_referer_redirects_to_the_pin_page_with_the_error(): void
    {
        $this->get(route('portal.link', $this->token));
        $this->post(route('portal.pin.check'), ['pin' => '000000'.'0'])->assertRedirect(route('portal.pin'));
        $this->post(route('portal.pin.check'), ['pin' => ''])->assertRedirect(route('portal.pin'))->assertSessionHasErrors('pin');
    }

    public function test_member_pages_are_not_cached(): void
    {
        $this->signIn();
        $this->get(route('portal.home'))->assertOk()->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $this->get(route('portal.home'))->headers->get('Cache-Control'));
    }

    public function test_pin_page_is_not_cached(): void
    {
        $this->get(route('portal.link', $this->token));
        $this->assertStringContainsString('no-store', (string) $this->get(route('portal.pin'))->headers->get('Cache-Control'));
    }

    public function test_opening_another_members_link_replaces_the_unlocked_member(): void
    {
        $this->signIn();
        $other = Party::factory()->create(['name' => 'Karim Uddin']);
        ['access' => $b] = $this->portal->issue($other, User::factory()->withRole(Role::Manager)->create());

        $this->get(route('portal.link', decrypt($b->token_encrypted)))->assertRedirect(route('portal.pin'));
        $this->get(route('portal.home'))->assertRedirect(route('portal.pin'));
        $this->get(route('portal.pin'))->assertOk()->assertSee('Karim Uddin')->assertDontSee('Rahima Begum');
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
