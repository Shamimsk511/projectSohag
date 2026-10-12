<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Party;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->area = Area::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Karim Mia',
            'phone' => '01711223344',
            'address' => 'House 5, Road 2',
            'area_id' => $this->area->id,
            'status' => ActiveStatus::Active->value,
        ], $overrides);
    }

    public function test_manager_creates_party_with_only_required_fields_and_gets_sequential_codes(): void
    {
        $this->actingAs($this->manager)->post(route('admin.parties.store'), $this->validPayload())->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('admin.parties.store'), $this->validPayload(['name' => 'Second']))->assertSessionHasNoErrors();

        $first = Party::where('name', 'Karim Mia')->sole();
        $second = Party::where('name', 'Second')->sole();

        $this->assertSame(sprintf('P-%05d', $first->id), $first->party_code);
        $this->assertSame(sprintf('P-%05d', $second->id), $second->party_code);
        $this->assertNull($first->national_id);
        $this->assertNull($first->photo_path);
    }

    public function test_store_redirects_to_the_party_profile(): void
    {
        $response = $this->actingAs($this->manager)->post(route('admin.parties.store'), $this->validPayload());

        $response->assertRedirect(route('admin.parties.show', Party::sole()));
    }

    public function test_optional_fields_and_bengali_digits_are_saved(): void
    {
        $this->actingAs($this->manager)->post(route('admin.parties.store'), $this->validPayload([
            'phone' => '০১৭১১২২৩৩৪৪',
            'father_or_husband_name' => 'Abdul Mia',
            'mother_name' => 'Rahima Begum',
            'date_of_birth' => '1990-05-01',
            'gender' => 'female',
            'national_id' => '১২৩৪৫৬৭৮৯০',
            'nominee_name' => 'Salma',
            'nominee_relation' => 'Daughter',
            'nominee_phone' => '01811223344',
            'nominee_national_id' => '1234567890123',
        ]))->assertSessionHasNoErrors();

        $party = Party::sole();
        $this->assertSame('01711223344', $party->phone);
        $this->assertSame('1234567890', $party->national_id);
        $this->assertSame('1990-05-01', $party->date_of_birth?->toDateString());
        $this->assertSame('Daughter', $party->nominee_relation);
    }

    public function test_required_fields_and_formats_are_validated(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.parties.store'), ['national_id' => '123', 'phone' => '12345', 'gender' => 'x'])
            ->assertSessionHasErrors(['name', 'address', 'area_id', 'status', 'phone', 'national_id', 'gender']);
    }

    public function test_national_id_is_unique(): void
    {
        Party::factory()->create(['national_id' => '1234567890']);

        $this->actingAs($this->manager)
            ->post(route('admin.parties.store'), $this->validPayload(['national_id' => '1234567890']))
            ->assertSessionHasErrors('national_id');
    }

    public function test_inactive_area_is_rejected_for_new_party_but_kept_on_existing_one(): void
    {
        $closed = Area::factory()->inactive()->create();

        $this->actingAs($this->manager)
            ->post(route('admin.parties.store'), $this->validPayload(['area_id' => $closed->id]))
            ->assertSessionHasErrors('area_id');

        $party = Party::factory()->create(['area_id' => $closed->id]);

        $this->actingAs($this->manager)
            ->put(route('admin.parties.update', $party), $this->validPayload(['area_id' => $closed->id]))
            ->assertSessionHasNoErrors();
    }

    public function test_create_form_only_offers_active_areas(): void
    {
        Area::factory()->create(['name' => 'Open Area']);
        Area::factory()->inactive()->create(['name' => 'Closed Area']);

        $this->actingAs($this->manager)
            ->get(route('admin.parties.create'))
            ->assertOk()
            ->assertSee('Open Area')
            ->assertDontSee('Closed Area');
    }

    public function test_manager_updates_party_without_changing_its_code(): void
    {
        $party = Party::factory()->create();
        $code = $party->party_code;

        $this->actingAs($this->manager)
            ->put(route('admin.parties.update', $party), $this->validPayload(['name' => 'Renamed', 'status' => 'inactive']))
            ->assertRedirect(route('admin.parties.show', $party));

        $party->refresh();
        $this->assertSame('Renamed', $party->name);
        $this->assertSame(ActiveStatus::Inactive, $party->status);
        $this->assertSame($code, $party->party_code);
    }

    public function test_profile_page_shows_party_details(): void
    {
        $party = Party::factory()->create(['name' => 'Jamila Khatun', 'nominee_name' => 'Rafiq']);

        $this->actingAs($this->manager)
            ->get(route('admin.parties.show', $party))
            ->assertOk()
            ->assertSee('Jamila Khatun')
            ->assertSee($party->party_code)
            ->assertSee('Rafiq');
    }

    public function test_accountant_can_view_but_not_change_parties(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $party = Party::factory()->create();

        $this->actingAs($accountant)->get(route('admin.parties.index'))->assertOk();
        $this->actingAs($accountant)->get(route('admin.parties.show', $party))->assertOk()->assertDontSee(route('admin.parties.edit', $party));
        $this->actingAs($accountant)->get(route('admin.parties.create'))->assertForbidden();
        $this->actingAs($accountant)->post(route('admin.parties.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($accountant)->get(route('admin.parties.edit', $party))->assertForbidden();
        $this->actingAs($accountant)->put(route('admin.parties.update', $party), $this->validPayload())->assertForbidden();
    }

    public function test_field_officer_has_no_admin_party_access(): void
    {
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $party = Party::factory()->create();

        $this->actingAs($officer)->get(route('admin.parties.index'))->assertForbidden();
        $this->actingAs($officer)->get(route('admin.parties.show', $party))->assertForbidden();
        $this->actingAs($officer)->get(route('admin.parties.data'))->assertForbidden();
    }

    public function test_menu_shows_parties_to_accountant_and_areas_only_to_managers(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();

        $this->actingAs($accountant)->get(route('admin.dashboard'))
            ->assertSee(route('admin.parties.index'))
            ->assertDontSee(route('admin.areas.index'));
    }

    public function test_menu_shows_areas_to_manager(): void
    {
        $this->actingAs($this->manager)->get(route('admin.dashboard'))
            ->assertSee(route('admin.parties.index'))
            ->assertSee(route('admin.areas.index'));
    }
}
