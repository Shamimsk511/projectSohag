<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole(Role::Admin)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahim Uddin',
            'email' => 'rahim@example.com',
            'phone' => '01711223344',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'role' => Role::FieldOfficer->value,
            'status' => UserStatus::Active->value,
        ], $overrides);
    }

    public function test_admin_sees_the_user_list(): void
    {
        $officer = User::factory()->withRole(Role::FieldOfficer)->create(['phone' => '01811223344']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($officer->name)
            ->assertSee('01811223344')
            ->assertSee(__('app.roles.field_officer'));
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function nonAdminOfficeRoles(): array
    {
        return [
            'manager' => [Role::Manager],
            'accountant' => [Role::Accountant],
        ];
    }

    #[DataProvider('nonAdminOfficeRoles')]
    public function test_non_admin_staff_cannot_manage_users(Role $role): void
    {
        $user = User::factory()->withRole($role)->create();
        $other = User::factory()->withRole(Role::FieldOfficer)->create();

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.users.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.edit', $other))->assertForbidden();
        $this->actingAs($user)->put(route('admin.users.update', $other), $this->validPayload())->assertForbidden();
    }

    public function test_menu_shows_users_link_to_admin(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertSee(route('admin.users.index'));
    }

    /**
     * Separate test: AdminLTE caches the built menu for the life of the app instance.
     */
    public function test_menu_hides_users_link_from_manager(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Manager)->create())
            ->get('/admin')
            ->assertDontSee(route('admin.users.index'));
    }

    public function test_admin_creates_a_user_with_a_role(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->validPayload())
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $user = User::where('email', 'rahim@example.com')->sole();
        $this->assertSame('01711223344', $user->phone);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue(Hash::check('secret-pass-123', $user->password));
        $this->assertSame([Role::FieldOfficer->value], $user->getRoleNames()->all());
    }

    public function test_create_form_renders(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('name="role"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'bad phone' => [['phone' => '12345'], 'phone'],
            'unconfirmed password' => [['password_confirmation' => 'different'], 'password'],
            'missing password' => [['password' => '', 'password_confirmation' => ''], 'password'],
            'unknown role' => [['role' => 'superuser'], 'role'],
            'unknown status' => [['status' => 'banned'], 'status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_store_validates_input(array $overrides, string $field): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->validPayload($overrides))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseMissing('users', ['email' => 'rahim@example.com']);
    }

    public function test_email_and_phone_must_be_unique(): void
    {
        User::factory()->create(['email' => 'rahim@example.com', 'phone' => '01711223344']);

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->validPayload())
            ->assertSessionHasErrors(['email', 'phone']);
    }

    public function test_admin_updates_a_user_and_changes_their_role(): void
    {
        $user = User::factory()->withRole(Role::FieldOfficer)->create(['phone' => '01711223344']);
        $oldHash = $user->password;

        $this->actingAs($this->admin)
            ->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertSee($user->email);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $user), $this->validPayload([
                'email' => $user->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => Role::Manager->value,
            ]))
            ->assertRedirect(route('admin.users.index'));

        $user->refresh();
        $this->assertSame('Rahim Uddin', $user->name);
        $this->assertSame($oldHash, $user->password, 'Blank password keeps the old one.');
        $this->assertSame([Role::Manager->value], $user->getRoleNames()->all());
    }

    public function test_update_can_set_a_new_password(): void
    {
        $user = User::factory()->withRole(Role::FieldOfficer)->create();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $user), $this->validPayload([
                'email' => $user->email,
                'password' => 'brand-new-pass-1',
                'password_confirmation' => 'brand-new-pass-1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-pass-1', $user->refresh()->password));
    }

    public function test_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $payload = $this->validPayload(['email' => $this->admin->email, 'password' => '', 'password_confirmation' => '']);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->admin), [...$payload, 'role' => Role::Manager->value])
            ->assertSessionHasErrors('role');

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->admin), [...$payload, 'role' => Role::Admin->value, 'status' => UserStatus::Inactive->value])
            ->assertSessionHasErrors('status');

        $this->admin->refresh();
        $this->assertTrue($this->admin->hasRole(Role::Admin->value));
        $this->assertSame(UserStatus::Active, $this->admin->status);
    }

    public function test_users_cannot_be_deleted(): void
    {
        $user = User::factory()->withRole(Role::FieldOfficer)->create();

        $this->actingAs($this->admin)
            ->delete('/admin/users/'.$user->id)
            ->assertStatus(405);

        $this->assertModelExists($user);
    }

    public function test_inactive_user_cannot_sign_in(): void
    {
        $user = User::factory()->withRole(Role::FieldOfficer)->inactive()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('app.account_inactive')]);

        $this->assertGuest();
    }

    public function test_deactivated_user_is_signed_out_on_next_request(): void
    {
        $user = User::factory()->withRole(Role::FieldOfficer)->create();
        $this->actingAs($user)->get('/field')->assertOk();

        $user->update(['status' => UserStatus::Inactive]);

        $this->get('/field')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('app.account_inactive')]);

        $this->assertGuest();
    }
}
