<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Area;
use App\Models\Party;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewMemberFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $officer;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Rahim Officer']);
        $this->area = Area::factory()->create();
    }

    public function test_create_form_offers_active_field_officers(): void
    {
        User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Gone Officer', 'status' => UserStatus::Inactive]);

        $this->actingAs($this->manager)->get(route('admin.parties.create'))
            ->assertOk()
            ->assertSee('name="field_officer_id"', false)
            ->assertSee('Rahim Officer')
            ->assertDontSee('Gone Officer');
    }

    public function test_new_member_is_assigned_and_lands_on_open_account_choices(): void
    {
        $response = $this->actingAs($this->manager)
            ->post(route('admin.parties.store'), $this->payload(['field_officer_id' => $this->officer->id]))
            ->assertSessionHasNoErrors();

        $party = Party::sole();
        $response->assertRedirect(route('admin.parties.show', $party));
        $this->assertSame($this->officer->id, $party->activeAssignment()->value('field_officer_id'));

        $page = $this->get(route('admin.parties.show', $party))->assertOk()->assertSee(__('app.parties.first_account'));
        foreach (['loan', 'saving', 'fdr'] as $type) {
            $page->assertSee(e(route('admin.parties.accounts.create', [$party, 'type' => $type])), false);
        }
    }

    public function test_without_officer_the_member_stays_unassigned(): void
    {
        $this->actingAs($this->manager)->post(route('admin.parties.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertNull(Party::sole()->activeAssignment()->value('field_officer_id'));
    }

    public function test_officer_must_be_an_active_field_officer_and_nothing_is_saved_otherwise(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.parties.store'), $this->payload(['field_officer_id' => $this->manager->id]))
            ->assertSessionHasErrors('field_officer_id');

        $this->assertSame(0, Party::count());
    }

    public function test_officer_cannot_be_set_without_assign_permission(): void
    {
        $clerk = User::factory()->withRole(Role::Accountant)->create();
        $clerk->givePermissionTo(Permission::ManageParties->value);

        $this->actingAs($clerk)
            ->post(route('admin.parties.store'), $this->payload(['field_officer_id' => $this->officer->id]))
            ->assertSessionHasErrors('field_officer_id');
    }

    public function test_open_account_form_preselects_the_type_from_the_link(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($this->manager)->get(route('admin.parties.accounts.create', [$party, 'type' => 'loan']))
            ->assertOk()
            ->assertSeeInOrder(['id="type-loan"', 'checked'], false);
    }

    public function test_first_account_choices_disappear_once_the_member_has_an_account(): void
    {
        $party = Party::factory()->create();
        Account::factory()->for($party)->create();

        $this->actingAs($this->manager)->get(route('admin.parties.show', $party))
            ->assertOk()
            ->assertDontSee(__('app.parties.first_account'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Karim Mia',
            'phone' => '01711223344',
            'address' => 'House 5, Road 2',
            'area_id' => $this->area->id,
            'status' => ActiveStatus::Active->value,
        ];
    }
}
