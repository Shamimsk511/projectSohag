<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_creates_an_active_admin(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Head Office')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'long-enough-pass')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.com')->sole();
        $this->assertTrue($admin->hasRole(Role::Admin->value));
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertTrue(Hash::check('long-enough-pass', $admin->password));
    }

    public function test_it_rejects_a_taken_email(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Head Office')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'long-enough-pass')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }
}
