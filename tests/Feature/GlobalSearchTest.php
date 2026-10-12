<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Account;
use App\Models\Party;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
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

    public function test_suggestions_match_name_phone_member_number_and_account_number(): void
    {
        $amina = Party::factory()->create(['name' => 'Amina Begum', 'phone' => '01711000001', 'client_number' => 'OLD-17']);
        $karim = Party::factory()->create(['name' => 'Karim', 'phone' => '01911000002']);
        Account::factory()->for($karim)->create(['account_number' => 'SV-000042']);

        $names = fn (string $term): array => $this->actingAs($this->manager)
            ->getJson(route('admin.search', ['q' => $term]))
            ->assertOk()
            ->json('results.*.name');

        $this->assertSame(['Amina Begum'], $names('amina'));
        $this->assertSame(['Amina Begum'], $names('০১৭১১'));
        $this->assertSame(['Amina Begum'], $names('OLD-17'));
        $this->assertSame(['Karim'], $names('SV-000042'));
        $this->assertSame([], $names('nobody'));

        $this->actingAs($this->manager)->getJson(route('admin.search', ['q' => 'amina']))
            ->assertJsonPath('results.0.url', route('admin.parties.show', $amina));
    }

    public function test_enter_goes_to_the_only_match_or_to_the_prefilled_list(): void
    {
        $amina = Party::factory()->create(['name' => 'Amina Begum']);
        Party::factory()->create(['name' => 'Amina Khatun']);

        $this->actingAs($this->manager)->get(route('admin.search', ['q' => 'Begum']))
            ->assertRedirect(route('admin.parties.show', $amina));
        $this->actingAs($this->manager)->get(route('admin.search', ['q' => 'Amina']))
            ->assertRedirect(route('admin.parties.index', ['search' => 'Amina']));
    }

    public function test_top_bar_shows_search_and_the_dhaka_date(): void
    {
        Carbon::setTestNow('2026-10-12 19:00:00'); // 13 Oct, 01:00 in Dhaka

        $this->actingAs($this->manager)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('id="global-search"', false)
            ->assertSee('১৩ অক্টোবর ২০২৬');
    }
}
