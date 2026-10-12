<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_login_page_uses_the_adminlte_auth_layout(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('login-page', false);
    }

    public function test_dashboard_renders_with_bundled_assets_only(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Admin)->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('app-wrapper', false)
            ->assertDontSee('vendor/adminlte/dist/css', false)
            ->assertDontSee('vendor/bootstrap/', false)
            ->assertDontSee('vendor/overlayscrollbars/', false)
            ->assertDontSee('vendor/fonts/', false)
            ->assertDontSee('cdn.jsdelivr.net', false);
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
