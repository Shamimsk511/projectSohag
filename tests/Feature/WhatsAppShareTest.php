<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Enums\WhatsAppKind;
use App\Models\Account;
use App\Models\CollectionPlan;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppLog;
use App\Services\PortalAccessService;
use App\Services\WhatsAppService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsAppShareTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 04:05:00'); // 10:05 Dhaka
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Rina']);
    }

    public function test_officer_logs_a_reminder_on_own_row_and_gets_the_label(): void
    {
        $schedule = $this->schedule($this->officer);

        $this->actingAs($this->officer)
            ->postJson(route('field.whatsapp.log'), ['kind' => 'reminder', 'schedule_id' => $schedule->id])
            ->assertOk()
            ->assertJsonPath('label', __('app.whatsapp.opened', ['time' => '১০:০৫', 'name' => 'Rina']));

        $log = WhatsAppLog::query()->sole();
        $this->assertSame($schedule->party_id, $log->party_id);
        $this->assertSame($schedule->party->phone, $log->phone);
        $this->assertSame($this->officer->id, $log->sent_by);
    }

    public function test_officer_cannot_log_a_reminder_on_another_officers_row(): void
    {
        $schedule = $this->schedule(User::factory()->withRole(Role::FieldOfficer)->create());

        $this->actingAs($this->officer)
            ->postJson(route('field.whatsapp.log'), ['kind' => 'reminder', 'schedule_id' => $schedule->id])
            ->assertForbidden();
        $this->assertSame(0, WhatsAppLog::query()->count());
    }

    public function test_office_staff_log_reminders_and_receipts_from_admin(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $schedule = $this->schedule($this->officer);
        $receipt = Transaction::factory()->of($schedule->account, TransactionType::LoanCollection, '100')->create(['receipt_no' => 'R-202610-00001']);

        $this->actingAs($manager)->postJson(route('admin.whatsapp.log'), ['kind' => 'reminder', 'schedule_id' => $schedule->id])->assertOk();
        $this->actingAs($manager)->postJson(route('admin.whatsapp.log'), ['kind' => 'receipt', 'transaction_id' => $receipt->id])->assertOk();
        $this->assertSame(2, WhatsAppLog::query()->count());
    }

    public function test_receipt_share_needs_receipt_access(): void
    {
        $other = User::factory()->withRole(Role::FieldOfficer)->create();
        $receipt = Transaction::factory()->of(Account::factory()->loan('1000')->create(), TransactionType::LoanCollection, '100')
            ->create(['receipt_no' => 'R-202610-00002', 'field_officer_id' => $other->id]);

        $this->actingAs($this->officer)
            ->postJson(route('field.whatsapp.log'), ['kind' => 'receipt', 'transaction_id' => $receipt->id])
            ->assertForbidden();
    }

    public function test_notice_only_for_disbursement_and_withdrawal(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $loan = Account::factory()->loan('1000')->create();
        $given = Transaction::factory()->of($loan, TransactionType::LoanDisbursement, '1000')->create();
        $collection = Transaction::factory()->of($loan, TransactionType::LoanCollection, '100')->create();

        $this->actingAs($accountant)->postJson(route('admin.whatsapp.log'), ['kind' => 'notice', 'transaction_id' => $given->id])->assertOk();
        $this->actingAs($accountant)->postJson(route('admin.whatsapp.log'), ['kind' => 'notice', 'transaction_id' => $collection->id])->assertForbidden();
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $this->actingAs($this->officer)->postJson(route('field.whatsapp.log'), ['kind' => 'reminder'])->assertUnprocessable();
        $this->actingAs($this->officer)->postJson(route('field.whatsapp.log'), ['kind' => 'sms', 'schedule_id' => 1])->assertUnprocessable();
    }

    public function test_reminder_labels_cover_only_that_dhaka_day(): void
    {
        $schedule = $this->schedule($this->officer);
        $service = app(WhatsAppService::class);

        Carbon::setTestNow('2026-10-11 17:59:00'); // 23:59 Dhaka, previous day
        $service->record(WhatsAppKind::Reminder, $schedule->party, $schedule, null, $this->officer);
        Carbon::setTestNow('2026-10-11 18:01:00'); // 00:01 Dhaka on the 12th
        $service->record(WhatsAppKind::Reminder, $schedule->party, $schedule, null, $this->officer);

        $labels = $service->reminderLabels([$schedule->id], Carbon::parse('2026-10-12'));
        $this->assertSame([$schedule->id => __('app.whatsapp.opened', ['time' => '০০:০১', 'name' => 'Rina'])], $labels);
        $this->assertSame([], $service->reminderLabels([$schedule->id], Carbon::parse('2026-10-13')));
    }

    public function test_reminder_text_names_member_account_and_amount_due(): void
    {
        $schedule = $this->schedule($this->officer);
        $text = app(WhatsAppService::class)->reminderText($schedule, Money::fromTaka('500'));

        $this->assertStringContainsString($schedule->party->name, $text);
        $this->assertStringContainsString($schedule->account->account_number, $text);
        $this->assertStringContainsString(Money::fromTaka('500')->format(), $text);
        $this->assertStringContainsString('১২/১০/২০২৬', $text);
    }

    public function test_latest_reminder_on_the_day_wins(): void
    {
        $schedule = $this->schedule($this->officer);
        $manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Karim']);
        $service = app(WhatsAppService::class);

        $service->record(WhatsAppKind::Reminder, $schedule->party, $schedule, null, $this->officer);
        Carbon::setTestNow('2026-10-12 05:30:00'); // 11:30 Dhaka
        $service->record(WhatsAppKind::Reminder, $schedule->party, $schedule, null, $manager);

        $this->assertSame(
            [$schedule->id => __('app.whatsapp.opened', ['time' => '১১:৩০', 'name' => 'Karim'])],
            $service->reminderLabels([$schedule->id], Carbon::parse('2026-10-12')),
        );
    }

    public function test_log_is_refused_when_the_member_has_no_mobile_number(): void
    {
        $party = Party::factory()->create(['phone' => '0211223344']);
        $schedule = CollectionSchedule::factory()->create([
            'account_id' => Account::factory()->loan('1000')->create(['party_id' => $party->id])->id,
            'field_officer_id' => $this->officer->id,
            'due_date' => '2026-10-12',
        ]);

        $this->actingAs($this->officer)
            ->postJson(route('field.whatsapp.log'), ['kind' => 'reminder', 'schedule_id' => $schedule->id])
            ->assertUnprocessable();
        $this->assertSame(0, WhatsAppLog::query()->count());
    }

    public function test_disbursement_notice_lists_the_installment_only_with_a_plan(): void
    {
        $service = app(WhatsAppService::class);
        $loan = Account::factory()->loan('1200')->create();
        $given = Transaction::factory()->of($loan, TransactionType::LoanDisbursement, '1000')->create();

        $without = $service->noticeText($given, $loan);
        $this->assertStringContainsString(Money::fromTaka('1000')->format(), $without);
        $this->assertStringContainsString(Money::fromTaka('1200')->format(), $without);
        $this->assertStringNotContainsString(__('app.whatsapp.installment_line', ['amount' => '', 'frequency' => '']), $without);

        CollectionPlan::factory()->create(['account_id' => $loan->id, 'installment_amount' => Money::fromTaka('100')]);
        $with = $service->noticeText($given, $loan->fresh());
        $this->assertStringContainsString(
            __('app.whatsapp.installment_line', ['amount' => Money::fromTaka('100')->format(), 'frequency' => __('app.schedules.frequencies.daily')]),
            $with,
        );
    }

    public function test_withdrawal_notice_shows_the_balance_after_it(): void
    {
        $saving = Account::factory()->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '1000')->create();
        $out = Transaction::factory()->of($saving, TransactionType::SavingWithdrawal, '300')->create();

        $text = app(WhatsAppService::class)->noticeText($out, $saving);

        $this->assertStringContainsString(Money::fromTaka('300')->format(), $text);
        $this->assertStringContainsString(__('app.whatsapp.withdrawn', [
            'app' => config('app.name'), 'amount' => Money::fromTaka('300')->format(), 'account' => $saving->account_number,
            'date' => '১২/১০/২০২৬', 'balance' => Money::fromTaka('700')->format(),
        ]), $text);
    }

    public function test_notice_text_is_null_for_a_collection(): void
    {
        $loan = Account::factory()->loan('1000')->create();
        $collection = Transaction::factory()->of($loan, TransactionType::LoanCollection, '100')->create();

        $this->assertNull(app(WhatsAppService::class)->noticeText($collection, $loan));
    }

    public function test_receipt_text_shows_the_standing_for_each_account_type(): void
    {
        $service = app(WhatsAppService::class);

        $loan = Account::factory()->loan('1000')->create();
        $paid = Transaction::factory()->of($loan, TransactionType::LoanCollection, '100')->create(['receipt_no' => 'R-202610-00007']);
        $text = $service->receiptText($paid->load('account'));
        $this->assertStringContainsString('R-202610-00007', $text);
        $this->assertStringContainsString($loan->party->name, $text);
        $this->assertStringContainsString(app()->getLocale() === 'bn' ? 'ধন্যবাদ' : 'Thank you', $text);
        $this->assertStringContainsString(Money::fromTaka('100')->format(), $text);
        $this->assertStringContainsString(__('app.whatsapp.remaining.loan').': '.Money::fromTaka('900')->format(), $text);

        $saving = Account::factory()->create();
        $deposit = Transaction::factory()->of($saving, TransactionType::SavingDeposit, '250')->create(['receipt_no' => 'R-202610-00008']);
        $this->assertStringContainsString(
            __('app.whatsapp.remaining.saving').': '.Money::fromTaka('250')->format(),
            $service->receiptText($deposit->load('account')),
        );

        $fdr = Account::factory()->fdr()->create();
        $held = Transaction::factory()->of($fdr, TransactionType::InvestmentDeposit, '5000')->create(['receipt_no' => 'R-202610-00009']);
        $this->assertStringContainsString(
            __('app.whatsapp.remaining.fdr').': '.Money::fromTaka('5000')->format(),
            $service->receiptText($held->load('account')),
        );
    }

    public function test_field_day_list_has_no_reminder_button_or_label(): void
    {
        $schedule = $this->schedule($this->officer);
        app(WhatsAppService::class)->record(WhatsAppKind::Reminder, $schedule->party, $schedule, null, $this->officer);

        $this->actingAs($this->officer)->get(route('field.home'))->assertOk()
            ->assertDontSee('https://wa.me/', false)
            ->assertDontSee('reminded_label', false);
    }

    public function test_dashboard_and_missed_report_offer_reminders_to_office_staff(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $schedule = $this->schedule($this->officer);

        $this->actingAs($manager)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('https://wa.me/880'.substr($schedule->party->phone, 1), false)
            ->assertSee(route('admin.whatsapp.log'), false);

        $schedule->forceFill(['due_date' => '2026-10-10', 'status' => ScheduleStatus::Missed])->save();
        $this->actingAs($manager)->get(route('admin.reports.missed-partial'))->assertOk()
            ->assertSee('https://wa.me/880'.substr($schedule->party->phone, 1), false);
    }

    public function test_receipt_page_has_a_whatsapp_button(): void
    {
        $schedule = $this->schedule($this->officer);
        $receipt = Transaction::factory()->of($schedule->account, TransactionType::LoanCollection, '100')
            ->create(['receipt_no' => 'R-202610-00009', 'field_officer_id' => $this->officer->id]);

        $this->actingAs($this->officer)->get(route('field.receipts.show', $receipt))->assertOk()
            ->assertSee('https://wa.me/880'.substr($schedule->party->phone, 1), false)
            ->assertSee(rawurlencode('R-202610-00009'), false);
    }

    public function test_reversed_receipt_is_never_shared(): void
    {
        $schedule = $this->schedule($this->officer);
        $receipt = Transaction::factory()->of($schedule->account, TransactionType::LoanCollection, '100')
            ->create(['receipt_no' => 'R-202610-00011', 'field_officer_id' => $this->officer->id]);
        Transaction::factory()->reversing($receipt)->create();

        $this->actingAs($this->officer)->get(route('field.receipts.show', $receipt))->assertOk()
            ->assertDontSee('https://wa.me/', false);
        $this->actingAs($this->officer)
            ->postJson(route('field.whatsapp.log'), ['kind' => 'receipt', 'transaction_id' => $receipt->id])
            ->assertUnprocessable();
        $this->assertSame(0, WhatsAppLog::query()->count());
    }

    public function test_reversed_notice_is_refused(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $given = Transaction::factory()->of(Account::factory()->loan('1000')->create(), TransactionType::LoanDisbursement, '1000')->create();
        Transaction::factory()->reversing($given)->create();

        $this->actingAs($accountant)->postJson(route('admin.whatsapp.log'), ['kind' => 'notice', 'transaction_id' => $given->id])
            ->assertUnprocessable();
        $this->assertSame(0, WhatsAppLog::query()->count());
    }

    public function test_office_fee_receipt_is_not_shared_as_a_thank_you(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $account = Account::factory()->loan('1000')->create();
        $fee = Transaction::factory()->of($account, TransactionType::OfficeIncome, '50')->create(['receipt_no' => 'R-202610-00012']);

        $this->actingAs($accountant)->get(route('admin.transactions.receipt', $fee))->assertOk()
            ->assertDontSee('https://wa.me/', false);
        $this->actingAs($accountant)->postJson(route('admin.whatsapp.log'), ['kind' => 'receipt', 'transaction_id' => $fee->id])
            ->assertUnprocessable();
        $this->assertSame(0, WhatsAppLog::query()->count());
    }

    private function schedule(User $officer): CollectionSchedule
    {
        return CollectionSchedule::factory()->create([
            'account_id' => Account::factory()->loan('1000')->create()->id,
            'field_officer_id' => $officer->id,
            'due_date' => '2026-10-12',
            'expected_amount' => Money::fromTaka('500'),
        ]);
    }

    public function test_withdrawal_flash_offers_a_whatsapp_notice(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $saving = Account::factory()->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '1000')->create();

        $this->actingAs($accountant)->post(route('admin.accounts.transactions.store', $saving), [
            'type' => 'saving_withdrawal', 'amount' => '300', 'transaction_date' => '2026-10-12', 'payment_method' => 'cash',
        ])->assertRedirect(route('admin.accounts.show', $saving))->assertSessionHas('whatsapp_notice');

        $this->actingAs($accountant)->get(route('admin.accounts.show', $saving))->assertOk()
            ->assertSee('https://wa.me/880'.substr($saving->party->phone, 1), false)
            ->assertSee(rawurlencode(Money::fromTaka('700')->format()), false);
    }

    public function test_recent_entries_offer_notices_only_for_loans_given_and_withdrawals(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $loan = Account::factory()->loan('1200')->create();
        Transaction::factory()->of($loan, TransactionType::LoanDisbursement, '1000')->create();
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '100')->create(['receipt_no' => 'R-202610-00003']);

        $html = (string) $this->actingAs($accountant)->get(route('admin.accounts.show', $loan))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '&quot;kind&quot;:&quot;notice&quot;'));
        $this->assertStringContainsString(rawurlencode(Money::fromTaka('1200')->format()), $html);
    }

    public function test_office_shares_the_portal_link_and_receipts_mention_it(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $schedule = $this->schedule($this->officer);
        $party = $schedule->party;
        $this->actingAs($manager)->post(route('admin.parties.portal.store', $party));
        $link = app(PortalAccessService::class)->link($party->portalAccess()->firstOrFail());

        $this->actingAs($manager)->get(route('admin.parties.show', $party))->assertOk()
            ->assertSee(rawurlencode($link), false);
        $this->actingAs($manager)->postJson(route('admin.whatsapp.log'), ['kind' => 'portal', 'party_id' => $party->id])->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->postJson(route('admin.whatsapp.log'), ['kind' => 'portal', 'party_id' => $party->id])->assertForbidden();

        $receipt = Transaction::factory()->of($schedule->account, TransactionType::LoanCollection, '100')
            ->create(['receipt_no' => 'R-202610-00031', 'field_officer_id' => $this->officer->id]);
        $text = app(WhatsAppService::class)->receiptText($receipt->load(['party', 'account']));
        $this->assertStringContainsString(route('portal.home'), $text);
        $this->assertStringNotContainsString('/m/', $text);
        $this->assertStringNotContainsString($link, $text);
    }
}
