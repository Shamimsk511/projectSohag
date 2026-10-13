<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionPlan;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MemberAccountProgressTest extends TestCase
{
    use RefreshDatabase;

    private Party $party;

    private Account $loan;

    private Account $saving;

    private Account $fdr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->party = Party::factory()->create();

        $this->loan = Account::factory()->loan('12000')->for($this->party)->create(['opened_at' => '2026-09-01']);
        CollectionPlan::factory()->create([
            'account_id' => $this->loan->id,
            'installment_amount' => Money::fromTaka('1000'),
            'starts_on' => '2026-09-28',
        ]);
        $this->entry($this->loan, TransactionType::LoanDisbursement, '10000', '2026-09-01');
        foreach (['2026-09-28' => ScheduleStatus::Paid, '2026-10-05' => ScheduleStatus::Paid, '2026-10-08' => ScheduleStatus::Paid,
            '2026-10-10' => ScheduleStatus::Missed, '2026-10-12' => ScheduleStatus::Pending, '2026-10-13' => ScheduleStatus::Pending,
            '2026-10-14' => ScheduleStatus::Cancelled] as $due => $status) {
            $row = CollectionSchedule::factory()->create([
                'account_id' => $this->loan->id,
                'party_id' => $this->party->id,
                'expected_amount' => Money::fromTaka('1000'),
                'due_date' => $due,
                'status' => $status,
            ]);
            if ($status === ScheduleStatus::Paid) {
                $this->entry($this->loan, TransactionType::LoanCollection, '1000', $due, $row->id);
            }
        }

        $this->saving = Account::factory()->for($this->party)->create(['contract_amount' => Money::fromTaka('50000')]);
        $this->entry($this->saving, TransactionType::SavingOpeningBalance, '5000', '2026-09-01');
        $this->entry($this->saving, TransactionType::SavingDeposit, '500', '2026-10-11');
        $reversed = $this->entry($this->saving, TransactionType::SavingDeposit, '777', '2026-10-12');
        Transaction::factory()->reversing($reversed)->create();
        $this->entry($this->saving, TransactionType::SavingWithdrawal, '200', '2026-10-12');

        $this->fdr = Account::factory()->fdr('12000')->for($this->party)->create();
        $this->entry($this->fdr, TransactionType::InvestmentDeposit, '10000', '2026-09-15');
        $this->entry($this->fdr, TransactionType::InvestmentReturn, '1000', '2026-10-01');
    }

    public function test_progress_figures_match_the_per_account_ledger(): void
    {
        $ledger = app(LedgerService::class);
        $progress = app(ReportService::class)->memberAccounts(
            $this->party->accounts()->get(),
            Carbon::parse('2026-10-12'),
        );

        $loan = $progress[$this->loan->id];
        $this->assertMoney('10000', $loan['given']);
        $this->assertMoney('2000', $loan['extra']);
        $this->assertMoney('3000', $loan['received']);
        $this->assertSame($ledger->loanOutstanding($this->loan->fresh())->poisha, $loan['remaining']->poisha);
        $this->assertMoney('1000', $loan['installment']);
        $this->assertSame(3, $loan['paid']);
        $this->assertSame(12, $loan['total']); // 3 paid + 9000 / 1000
        $this->assertSame(1, $loan['missed']);
        $this->assertSame('2026-10-12', $loan['next_due']?->toDateString());
        $this->assertSame('2026-10-08', $loan['last_paid']?->toDateString());
        $expectedEnd = app(CollectionService::class)->loanProgress($this->loan->fresh())['ends_on'] ?? null;
        $this->assertSame($expectedEnd?->toDateString(), $loan['ends_on']?->toDateString());

        $saving = $progress[$this->saving->id];
        $this->assertSame($ledger->savingBalance($this->saving)->poisha, $saving['remaining']->poisha);
        $this->assertMoney('5300', $saving['remaining']);
        $this->assertMoney('5500', $saving['saved']);
        $this->assertMoney('50000', $saving['contract']);
        $this->assertNull($saving['installment']);
        $this->assertNull($saving['next_due']);
        $this->assertSame('2026-10-11', $saving['last_paid']?->toDateString()); // opening balance is not a payment

        $fdr = $progress[$this->fdr->id];
        $this->assertMoney('9000', $fdr['held']);
        $this->assertSame($ledger->fdrRemaining($this->fdr)->poisha, $fdr['remaining']->poisha);
        $this->assertMoney('11000', $fdr['remaining']);
    }

    public function test_party_page_shows_progress_cards_and_the_top_line(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Manager)->create())
            ->get(route('admin.parties.show', $this->party))
            ->assertOk()
            ->assertSee(__('app.parties.progress.owes_us'))
            ->assertSee('৳৯,০০০.০০') // loan remaining
            ->assertSee(__('app.parties.progress.we_hold'))
            ->assertSee('৳১৬,৩০০.০০') // 5300 savings + 11000 FDR payback
            ->assertSee(__('app.parties.progress.installments', ['paid' => '৩', 'total' => '১২']))
            ->assertSee('১২/১০/২০২৬') // next due
            ->assertSee(route('admin.accounts.show', $this->loan), false)
            ->assertSee(route('admin.accounts.statement', $this->loan), false);
    }

    private function entry(Account $account, TransactionType $type, string $taka, string $date, ?int $scheduleId = null): Transaction
    {
        return Transaction::factory()->of($account, $type, $taka)->create([
            'transaction_date' => $date,
            'payment_method' => $type === TransactionType::SavingOpeningBalance ? null : 'cash',
            'schedule_id' => $scheduleId,
        ]);
    }

    private function assertMoney(string $taka, ?Money $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertSame(Money::fromTaka($taka)->poisha, $actual->poisha);
    }
}
