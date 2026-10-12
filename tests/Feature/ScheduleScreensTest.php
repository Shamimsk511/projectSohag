<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $accountant;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Rahim Officer']);
    }

    public function test_list_filters_by_day_officer_and_status_with_totals(): void
    {
        $mine = CollectionSchedule::factory()->create(['field_officer_id' => $this->officer->id, 'due_date' => '2026-10-12']);
        CollectionSchedule::factory()->create(['due_date' => '2026-10-12']);
        $tomorrow = CollectionSchedule::factory()->create(['field_officer_id' => $this->officer->id, 'due_date' => '2026-10-13']);

        $this->actingAs($this->accountant)->get(route('admin.schedules.index'))
            ->assertOk()
            ->assertSee($mine->account->account_number)
            ->assertDontSee($tomorrow->account->account_number)
            ->assertSee('২০০.০০')
            ->assertDontSee(__('app.schedules.reassign'));

        $this->actingAs($this->manager)->get(route('admin.schedules.index', ['officer' => 'none']))
            ->assertOk()
            ->assertDontSee($mine->account->account_number)
            ->assertSee(__('app.schedules.reassign'));

        $this->actingAs($this->manager)->get(route('admin.schedules.index', ['date' => '2026-10-13', 'officer' => $this->officer->id, 'status' => 'pending']))
            ->assertOk()
            ->assertSee($tomorrow->account->account_number);
    }

    public function test_field_officer_cannot_open_office_schedule_screens(): void
    {
        $schedule = CollectionSchedule::factory()->create();

        $this->actingAs($this->officer)->get(route('admin.schedules.index'))->assertForbidden();
        $this->actingAs($this->accountant)->get(route('admin.schedules.edit', $schedule))->assertForbidden();
        $this->actingAs($this->accountant)->put(route('admin.schedules.update', $schedule), [
            'due_date' => '2026-10-14', 'expected_amount' => '50',
        ])->assertForbidden();
    }

    public function test_manager_adds_edits_and_cancels_a_collection(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->manager)
            ->post(route('admin.accounts.schedules.store', $account), ['due_date' => '2026-10-15', 'expected_amount' => '300', 'notes' => 'Haat'])
            ->assertRedirect(route('admin.accounts.show', $account))
            ->assertSessionHasNoErrors();
        $schedule = CollectionSchedule::sole();

        $this->actingAs($this->manager)->get(route('admin.schedules.edit', $schedule))->assertOk()->assertSee('Haat');

        $this->actingAs($this->manager)
            ->put(route('admin.schedules.update', $schedule), ['due_date' => '2026-10-16', 'expected_amount' => '350'])
            ->assertRedirect(route('admin.accounts.show', $account))
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-10-16', $schedule->refresh()->due_date->toDateString());
        $this->assertNull($schedule->notes);

        $this->actingAs($this->manager)->post(route('admin.schedules.cancel', $schedule))->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('admin.schedules.cancel', $schedule), ['reason' => 'Moved away'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ScheduleStatus::Cancelled, $schedule->refresh()->status);
    }

    public function test_add_form_validates_input(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->manager)
            ->post(route('admin.accounts.schedules.store', $account), ['due_date' => '2026-10-11', 'expected_amount' => 'x'])
            ->assertSessionHasErrors(['due_date', 'expected_amount']);
        $this->assertSame(0, CollectionSchedule::count());
    }

    public function test_manager_hands_an_officers_day_to_another_officer(): void
    {
        $cover = User::factory()->withRole(Role::FieldOfficer)->create();
        $schedule = CollectionSchedule::factory()->create(['field_officer_id' => $this->officer->id, 'due_date' => '2026-10-12']);

        $this->actingAs($this->manager)
            ->post(route('admin.schedules.reassign'), [
                'date' => '2026-10-12', 'from_officer_id' => $this->officer->id, 'to_officer_id' => $cover->id,
            ])
            ->assertRedirect(route('admin.schedules.index', ['date' => '2026-10-12', 'officer' => $cover->id]))
            ->assertSessionHas('status');

        $this->assertSame($cover->id, $schedule->refresh()->field_officer_id);

        $this->actingAs($this->accountant)
            ->post(route('admin.schedules.reassign'), ['date' => '2026-10-12', 'from_officer_id' => 'none', 'to_officer_id' => $cover->id])
            ->assertForbidden();
    }
}
