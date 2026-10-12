<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(LedgerService::class);
    }

    public function test_saving_balance_is_deposits_minus_withdrawals_ignoring_reversed_rows(): void
    {
        $saving = Account::factory()->create();
        $other = Account::factory()->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '500')->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '250.50')->create();
        Transaction::factory()->of($saving, TransactionType::SavingWithdrawal, '100')->create();
        $mistake = Transaction::factory()->of($saving, TransactionType::SavingDeposit, '1000')->create();
        Transaction::factory()->reversing($mistake)->create();
        Transaction::factory()->of($other, TransactionType::SavingDeposit, '999')->create();

        $this->assertSame(Money::fromTaka('650.50')->poisha, $this->ledger->savingBalance($saving)->poisha);
    }

    public function test_loan_outstanding_is_contract_minus_collections(): void
    {
        $loan = Account::factory()->loan('12000')->create();
        Transaction::factory()->of($loan, TransactionType::LoanDisbursement, '10000')->create();
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '1000')->create();
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '500')->create();
        $reversed = Transaction::factory()->of($loan, TransactionType::LoanCollection, '700')->create();
        Transaction::factory()->reversing($reversed)->create();

        $this->assertSame(Money::fromTaka('1500')->poisha, $this->ledger->loanReceived($loan)->poisha);
        $this->assertSame(Money::fromTaka('10500')->poisha, $this->ledger->loanOutstanding($loan)->poisha);
    }

    public function test_remaining_balance_picks_the_figure_that_blocks_closing(): void
    {
        $loan = Account::factory()->loan('300')->create();
        $saving = Account::factory()->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '40')->create();

        $this->assertSame(30000, $this->ledger->remainingBalance($loan)->poisha);
        $this->assertSame(4000, $this->ledger->remainingBalance($saving)->poisha);
    }

    public function test_saving_statement_runs_the_balance_and_shows_reversals_beside_the_original(): void
    {
        $saving = Account::factory()->create();
        $deposit = Transaction::factory()->of($saving, TransactionType::SavingDeposit, '500')->create(['transaction_date' => '2026-10-01']);
        Transaction::factory()->of($saving, TransactionType::SavingWithdrawal, '200')->create(['transaction_date' => '2026-10-02']);
        $mistake = Transaction::factory()->of($saving, TransactionType::SavingDeposit, '1000')->create(['transaction_date' => '2026-10-02']);
        Transaction::factory()->reversing($mistake)->create(['transaction_date' => '2026-10-03']);
        Transaction::factory()->of($saving, TransactionType::Adjustment, '50')->create(['transaction_date' => '2026-10-03']);

        $statement = $this->ledger->statement($saving);

        $this->assertSame(0, $statement->opening->poisha);
        $this->assertSame(
            [[$deposit->id, 50000, null, 50000, false], [null, null, 20000, 30000, false], [$mistake->id, 100000, null, 130000, true], [null, -100000, null, 30000, false]],
            array_map(fn ($line) => [
                $line->transaction->is($deposit) || $line->transaction->is($mistake) ? $line->transaction->id : null,
                $line->in?->poisha, $line->out?->poisha, $line->running->poisha, $line->reversed,
            ], $statement->lines),
        );
        $this->assertSame($this->ledger->savingBalance($saving)->poisha, $statement->closing->poisha);
    }

    public function test_loan_statement_shows_disbursement_but_runs_only_the_received_total(): void
    {
        $loan = Account::factory()->loan('12000')->create();
        Transaction::factory()->of($loan, TransactionType::LoanDisbursement, '10000')->create(['transaction_date' => '2026-10-01']);
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '1000')->create(['transaction_date' => '2026-10-05']);
        $reversed = Transaction::factory()->of($loan, TransactionType::LoanCollection, '700')->create(['transaction_date' => '2026-10-06']);
        Transaction::factory()->reversing($reversed)->create(['transaction_date' => '2026-10-07']);

        $statement = $this->ledger->statement($loan);

        $this->assertSame(
            [[null, 1000000, 0], [100000, null, 100000], [70000, null, 170000], [-70000, null, 100000]],
            array_map(fn ($line) => [$line->in?->poisha, $line->out?->poisha, $line->running->poisha], $statement->lines),
        );
        $this->assertSame($this->ledger->loanReceived($loan)->poisha, $statement->closing->poisha);
    }

    public function test_statement_period_carries_earlier_rows_into_the_opening_figure(): void
    {
        $saving = Account::factory()->create();
        $other = Account::factory()->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '500')->create(['transaction_date' => '2026-09-30']);
        $original = Transaction::factory()->of($saving, TransactionType::SavingDeposit, '300')->create(['transaction_date' => '2026-09-30']);
        $inside = Transaction::factory()->of($saving, TransactionType::SavingWithdrawal, '100')->create(['transaction_date' => '2026-10-05']);
        Transaction::factory()->reversing($original)->create(['transaction_date' => '2026-10-06']);
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '900')->create(['transaction_date' => '2026-10-11']);
        Transaction::factory()->of($other, TransactionType::SavingDeposit, '999')->create(['transaction_date' => '2026-10-05']);

        $statement = $this->ledger->statement($saving, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'));

        $this->assertSame(80000, $statement->opening->poisha);
        $this->assertCount(2, $statement->lines);
        $this->assertTrue($statement->lines[0]->transaction->is($inside));
        $this->assertSame(-30000, $statement->lines[1]->in?->poisha);
        $this->assertSame(40000, $statement->closing->poisha);
    }

    public function test_transactions_cannot_be_updated_or_deleted(): void
    {
        $transaction = Transaction::factory()->create();

        try {
            $transaction->update(['description' => 'changed']);
            $this->fail('Update was allowed.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $transaction->delete();
    }
}
