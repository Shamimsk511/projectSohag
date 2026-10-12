<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Party;
use App\Models\PartyAssignment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PartyAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $officer;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $this->party = Party::factory()->create();
    }

    private function assign(User $actor, User|int $officer, ?Party $party = null): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.parties.assignment.store', $party ?? $this->party), [
            'field_officer_id' => $officer instanceof User ? $officer->id : $officer,
        ]);
    }

    public function test_manager_assigns_an_officer_dated_today_in_dhaka(): void
    {
        // 20:00 UTC is already the next day in Dhaka (UTC+6).
        $this->travelTo('2026-10-12 20:00:00');

        $this->assign($this->manager, $this->officer)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.parties.show', $this->party));

        $assignment = PartyAssignment::sole();
        $this->assertSame($this->party->id, $assignment->party_id);
        $this->assertSame($this->officer->id, $assignment->field_officer_id);
        $this->assertSame('2026-10-13', $assignment->assigned_from->toDateString());
        $this->assertNull($assignment->assigned_until);
        $this->assertSame($this->manager->id, $assignment->assigned_by);
    }

    public function test_reassigning_closes_the_previous_assignment_and_keeps_history(): void
    {
        $other = User::factory()->withRole(Role::FieldOfficer)->create();

        $this->travelTo('2026-10-01 04:00:00');
        $this->assign($this->manager, $this->officer)->assertSessionHasNoErrors();
        $this->travelTo('2026-10-12 04:00:00');
        $this->assign($this->manager, $other)->assertSessionHasNoErrors();

        $first = PartyAssignment::where('field_officer_id', $this->officer->id)->sole();
        $this->assertSame('2026-10-12', $first->assigned_until?->toDateString());
        $this->assertSame($this->manager->id, $first->ended_by);

        $current = $this->party->activeAssignment()->sole();
        $this->assertSame($other->id, $current->field_officer_id);
        $this->assertSame('2026-10-12', $current->assigned_from->toDateString());
        $this->assertSame(2, PartyAssignment::count());
    }

    public function test_assigning_the_current_officer_again_is_rejected(): void
    {
        $this->assign($this->manager, $this->officer)->assertSessionHasNoErrors();

        $this->assign($this->manager, $this->officer)->assertSessionHasErrors('field_officer_id');

        $this->assertSame(1, PartyAssignment::count());
    }

    public function test_only_active_field_officers_can_be_assigned(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $inactiveOfficer = User::factory()->inactive()->withRole(Role::FieldOfficer)->create();

        $this->assign($this->manager, $accountant)->assertSessionHasErrors('field_officer_id');
        $this->assign($this->manager, $inactiveOfficer)->assertSessionHasErrors('field_officer_id');
        $this->assign($this->manager, 999_999)->assertSessionHasErrors('field_officer_id');

        $this->assertSame(0, PartyAssignment::count());
    }

    public function test_inactive_party_cannot_be_assigned(): void
    {
        $inactive = Party::factory()->inactive()->create();

        $this->assign($this->manager, $this->officer, $inactive)->assertSessionHasErrors('field_officer_id');

        $this->assertSame(0, PartyAssignment::count());
    }

    public function test_ending_an_assignment_leaves_the_party_unassigned(): void
    {
        $this->assign($this->manager, $this->officer);
        $this->travelTo(now()->addDays(3));

        $this->actingAs($this->manager)->delete(route('admin.parties.assignment.destroy', $this->party))
            ->assertRedirect(route('admin.parties.show', $this->party));

        $assignment = PartyAssignment::sole();
        $this->assertNotNull($assignment->assigned_until);
        $this->assertSame($this->manager->id, $assignment->ended_by);
        $this->assertNull($this->party->activeAssignment()->first());
    }

    public function test_roles_without_assign_permission_are_forbidden(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();

        $this->assign($accountant, $this->officer)->assertForbidden();
        $this->actingAs($accountant)->delete(route('admin.parties.assignment.destroy', $this->party))->assertForbidden();
        $this->assign($this->officer, $this->officer)->assertForbidden();

        $this->assertSame(0, PartyAssignment::count());
    }

    public function test_admin_can_assign(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();

        $this->assign($admin, $this->officer)->assertSessionHasNoErrors();

        $this->assertSame(1, PartyAssignment::count());
    }

    public function test_officer_sees_only_currently_assigned_parties(): void
    {
        $other = User::factory()->withRole(Role::FieldOfficer)->create();
        $mine = $this->party;
        $theirs = Party::factory()->create();
        $formerlyMine = Party::factory()->create();

        $this->assign($this->manager, $this->officer, $mine);
        $this->assign($this->manager, $other, $theirs);
        $this->assign($this->manager, $this->officer, $formerlyMine);
        $this->assign($this->manager, $other, $formerlyMine);

        $this->assertSame([$mine->id], Party::assignedTo($this->officer)->pluck('id')->all());
        $this->assertTrue($this->officer->can('view', $mine));
        $this->assertFalse($this->officer->can('view', $theirs));
        $this->assertFalse($this->officer->can('view', $formerlyMine));
        $this->assertFalse($this->officer->can('viewAny', Party::class));
    }

    public function test_profile_shows_current_officer_history_and_assign_form(): void
    {
        $other = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Rahim Officer']);
        $this->officer->update(['name' => 'Karim Officer']);
        $this->assign($this->manager, $this->officer);
        $this->assign($this->manager, $other);

        $this->actingAs($this->manager)->get(route('admin.parties.show', $this->party))
            ->assertOk()
            ->assertSee('Rahim Officer')
            ->assertSee('Karim Officer')
            ->assertSee(route('admin.parties.assignment.store', $this->party), false)
            ->assertSee(route('admin.parties.assignment.destroy', $this->party), false);
    }

    public function test_profile_hides_assign_form_without_permission(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();

        $this->actingAs($accountant)->get(route('admin.parties.show', $this->party))
            ->assertOk()
            ->assertDontSee(route('admin.parties.assignment.store', $this->party), false);
    }
}
