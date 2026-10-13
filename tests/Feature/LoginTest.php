<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array<string, array{Role, string}>
     */
    public static function homeByRole(): array
    {
        return [
            'admin' => [Role::Admin, '/admin'],
            'manager' => [Role::Manager, '/admin'],
            'accountant' => [Role::Accountant, '/admin'],
            'field officer' => [Role::FieldOfficer, '/field'],
        ];
    }

    #[DataProvider('homeByRole')]
    public function test_login_redirects_to_the_role_home(Role $role, string $home): void
    {
        $user = User::factory()->withRole($role)->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($home);

        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('homeByRole')]
    public function test_homepage_links_signed_in_user_to_the_role_home(Role $role, string $home): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get('/')
            ->assertOk()
            ->assertSee('href="'.url($home).'"', false);
    }

    public function test_guest_sees_the_public_homepage_with_a_staff_login_link(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('app.home.hero_title'))
            ->assertSee('href="'.route('login').'"', false);
    }

    public function test_login_page_renders_without_the_admin_layout(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(__('app.login.heading'))
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false);
    }

    public function test_signed_in_user_visiting_login_is_sent_to_the_role_home(): void
    {
        $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())
            ->get('/login')
            ->assertRedirect('/field');
    }

    public function test_visitor_can_switch_the_language_to_english_and_back(): void
    {
        $this->get('/')->assertSee('সৃজনশীল মাল্টিপারপাস');

        $this->from('/')->get('/lang/en')->assertRedirect('/');
        $this->get('/')->assertSee('Build your tomorrow with your own hands')->assertSee('<html lang="en">', false);

        $this->get('/lang/bn');
        $this->get('/')->assertSee('নিজের হাতে গড়ুন আগামী দিন');
    }

    public function test_unknown_language_is_not_found(): void
    {
        $this->get('/lang/fr')->assertNotFound();
    }

    public function test_user_without_a_role_cannot_sign_in(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('app.no_role')]);

        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->withRole(Role::Admin)->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_signs_out_and_returns_to_login(): void
    {
        $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_field_officer_cannot_open_admin_area(): void
    {
        $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())
            ->get('/admin')
            ->assertForbidden();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function officeRoles(): array
    {
        return [
            'manager' => [Role::Manager],
            'accountant' => [Role::Accountant],
        ];
    }

    #[DataProvider('officeRoles')]
    public function test_office_staff_cannot_open_field_app(Role $role): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get('/field')
            ->assertForbidden();
    }

    public function test_admin_can_open_field_app(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Admin)->create())
            ->get('/field')
            ->assertOk();
    }

    public function test_field_layout_has_a_logout_button(): void
    {
        $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())
            ->get('/field')
            ->assertOk()
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee(__('app.logout'));
    }
}
