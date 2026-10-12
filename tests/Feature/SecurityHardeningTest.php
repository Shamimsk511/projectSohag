<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_carry_baseline_security_headers(): void
    {
        $this->withoutVite();

        $this->get(route('login'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security');
    }

    public function test_sync_endpoint_is_rate_limited_per_user(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($officer)->postJson(route('field.api.collections.sync'), [])->assertStatus(422);
        }

        $this->actingAs($officer)->postJson(route('field.api.collections.sync'), [])->assertStatus(429);
    }
}
