<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
