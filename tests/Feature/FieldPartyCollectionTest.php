<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\PartyAssignment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldPartyCollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $this->party = Party::factory()->create(['name' => 'Rahima Begum', 'client_number' => 'M-77']);
    }

    public function test_day_list_shows_one_searchable_card_per_client_with_all_their_rows(): void
    {
        $loan = $this->schedule(Account::factory()->loan()->for($this->party)->create(), '500');
        $saving = $this->schedule(Account::factory()->for($this->party)->create(), '100');
        $arrears = $this->schedule(Account::factory()->for($this->party)->create(), '50', [
            'due_date' => '2026-10-10', 'status' => ScheduleStatus::Missed,
        ]);

        $html = $this->actingAs($this->officer)->get(route('field.home'))
            ->assertOk()
            ->assertSee('js-party-search', false)
            ->assertSee($this->party->party_code)
            ->assertSee('M-77')
            ->assertSee(route('field.parties.collect', $this->party))
            ->assertSee(route('field.collections.create', $loan))
            ->assertSee(route('field.collections.create', $saving))
            ->assertSee(route('field.collections.create', $arrears))
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-party-card='));
        $this->assertStringContainsString('data-search="rahima begum '.strtolower($this->party->party_code).' m-77 '.$this->party->phone.'"', $html);
    }

    public function test_collect_all_page_lists_each_collectable_row_prefilled_with_its_remaining_due(): void
    {
        $loan = $this->schedule(Account::factory()->loan()->for($this->party)->create(), '500');
        $saving = $this->schedule(Account::factory()->for($this->party)->create(), '100');
        $paid = $this->schedule(Account::factory()->for($this->party)->create(), '70');
        $this->post(route('field.collections.store', $paid), $this->payload('70'));
        $otherOfficers = $this->schedule(Account::factory()->for($this->party)->create(), '30', [
            'field_officer_id' => User::factory()->withRole(Role::FieldOfficer)->create()->id,
        ]);

        $this->actingAs($this->officer)->get(route('field.parties.collect', $this->party))
            ->assertOk()
            ->assertSee('js-queue-collect-many', false)
            ->assertSee('data-schedule-id="'.$loan->id.'"', false)
            ->assertSee('data-schedule-id="'.$saving->id.'"', false)
            ->assertSee('value="500.00"', false)
            ->assertSee('value="100.00"', false)
            ->assertDontSee('data-schedule-id="'.$paid->id.'"', false)
            ->assertDontSee('data-schedule-id="'.$otherOfficers->id.'"', false);
    }

    public function test_collect_all_records_every_filled_row_and_skips_blank_ones(): void
    {
        $loan = $this->schedule(Account::factory()->loan()->for($this->party)->create(), '500');
        $saving = $this->schedule(Account::factory()->for($this->party)->create(), '100');
        $skipped = $this->schedule(Account::factory()->for($this->party)->create(), '40');

        $payload = [
            'payment_method' => 'cash',
            'items' => [
                ['schedule_id' => $loan->id, 'amount' => '500', 'client_uuid' => (string) Str::uuid()],
                ['schedule_id' => $saving->id, 'amount' => '১০০', 'client_uuid' => (string) Str::uuid()],
                ['schedule_id' => $skipped->id, 'amount' => '', 'client_uuid' => (string) Str::uuid()],
            ],
        ];

        $this->actingAs($this->officer)->post(route('field.parties.collections.store', $this->party), $payload)
            ->assertRedirect(route('field.home'))
            ->assertSessionHas('status');
        // A repeated submit posts nothing new (client_uuid).
        $this->post(route('field.parties.collections.store', $this->party), $payload)->assertRedirect(route('field.home'));

        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(ScheduleStatus::Paid, $loan->fresh()?->status);
        $this->assertSame(ScheduleStatus::Paid, $saving->fresh()?->status);
        $this->assertSame(ScheduleStatus::Pending, $skipped->fresh()?->status);
        $this->assertSame(TransactionType::SavingDeposit, Transaction::query()->where('schedule_id', $saving->id)->sole()->type);
    }

    public function test_collect_all_reports_rejected_rows_and_still_records_the_rest(): void
    {
        $loan = $this->schedule(Account::factory()->loan('1000')->for($this->party)->create(), '500');
        $saving = $this->schedule(Account::factory()->for($this->party)->create(), '100');

        $this->actingAs($this->officer)->post(route('field.parties.collections.store', $this->party), [
            'payment_method' => 'cash',
            'items' => [
                ['schedule_id' => $loan->id, 'amount' => '5000', 'client_uuid' => (string) Str::uuid()],
                ['schedule_id' => $saving->id, 'amount' => '100', 'client_uuid' => (string) Str::uuid()],
            ],
        ])->assertRedirect(route('field.home'))->assertSessionHas('status_error');

        $this->assertSame([$saving->id], Transaction::query()->pluck('schedule_id')->all());
    }

    public function test_collect_all_needs_at_least_one_amount(): void
    {
        $loan = $this->schedule(Account::factory()->loan()->for($this->party)->create(), '500');

        $this->actingAs($this->officer)->from(route('field.parties.collect', $this->party))
            ->post(route('field.parties.collections.store', $this->party), [
                'payment_method' => 'cash',
                'items' => [['schedule_id' => $loan->id, 'amount' => '', 'client_uuid' => (string) Str::uuid()]],
            ])->assertSessionHasErrors('items');

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_officer_cannot_collect_all_for_a_client_without_own_open_rows(): void
    {
        $this->schedule(Account::factory()->for($this->party)->create(), '100', [
            'field_officer_id' => User::factory()->withRole(Role::FieldOfficer)->create()->id,
        ]);

        $this->actingAs($this->officer)->get(route('field.parties.collect', $this->party))->assertForbidden();
        $this->post(route('field.parties.collections.store', $this->party), ['payment_method' => 'cash', 'items' => []])->assertForbidden();
    }

    public function test_officer_sees_photos_of_own_clients_only(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('p.jpg')->storeAs('parties', 'p.jpg', 'local');
        $this->party->forceFill(['photo_path' => $path])->save();
        $stranger = Party::factory()->create(['photo_path' => $path]);

        // Covering another officer's day: no party assignment, but a row on the officer's list.
        $this->schedule(Account::factory()->for($this->party)->create(), '100');

        $this->actingAs($this->officer)->get(route('field.parties.photo', $this->party))->assertOk();
        $this->get(route('field.parties.photo', $stranger))->assertForbidden();

        PartyAssignment::factory()->create(['party_id' => $stranger->id, 'field_officer_id' => $this->officer->id]);
        $this->get(route('field.parties.photo', $stranger))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function schedule(Account $account, string $expectedTaka, array $attributes = []): CollectionSchedule
    {
        return CollectionSchedule::factory()->create([
            'account_id' => $account->id,
            'field_officer_id' => $this->officer->id,
            'expected_amount' => Money::fromTaka($expectedTaka),
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $taka): array
    {
        $this->actingAs($this->officer);

        return ['amount' => $taka, 'payment_method' => 'cash', 'client_uuid' => (string) Str::uuid()];
    }
}
