# FDR Accounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add FDR accounts: members deposit (lump sum, installments or several posts), the NGO pays back an admin-agreed amount, possibly early. Record only; never compute payback, rate or profit.

**Architecture:** New `AccountType::Fdr` with the existing-but-unused `investment_deposit` (in, cash, receipt) and `investment_return` (out, cash) ledger types, enforced in `TransactionService`, computed in `LedgerService`. Installment collection reuses the plan/schedule/field flow through `AccountType::collectionType()`; a new `TransactionType::collectionTypes()` replaces every hard-coded `[LoanCollection, SavingDeposit]` list.

**Tech Stack:** Laravel 13, PHP 8.3-safe syntax, Blade + AdminLTE 4/Bootstrap 5, PHPUnit on SQLite `:memory:`, Pint, Larastan level 6 (0 errors, no baseline).

## Global Constraints

- No `interest`, `profit`, rate or auto-compute wording/columns anywhere. UI words: "agreed payback", "deposited", "paid back", "remaining payback". `DomainInvariantsTest` scans source for forbidden words (interest/profit/সুদ/মুনাফা/"Shariah compliant").
- `TransactionService` is the only writer of `transactions`. Money = integer poisha via `Money`. Transactions immutable; fix by reversal.
- Every financial write audited (existing paths already do).
- UI text only via `lang/bn/app.php` and `lang/en/app.php` (`LangFilesTest` enforces parity). No hardcoded Bengali in Blade.
- Compare date columns by range, never equality (SQLite stores `Y-m-d 00:00:00`).
- No MySQL-only SQL. No Tailwind, no CDN. PHP 8.3-compatible syntax only.
- No migration: `accounts.account_type` is `string(20)`, `transactions.type` is a string column. Verify with `database-schema` in Task 1 Step 1.
- Run in each task: `php artisan test --compact --filter=<name>`; at the end `php artisan test --compact`, `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`.
- Spec amendment (this plan wins): `fdrRemaining = (contract_amount ?? deposited) - returned`. With no agreed payback set, the remaining payback is what was deposited, so an FDR with money in it cannot be closed silently.

## File Structure

- Modify `app/Enums/AccountType.php`, `app/Enums/TransactionType.php`, `app/Models/Account.php`, `database/factories/AccountFactory.php`: type, helpers, factory state.
- Modify `app/Services/LedgerService.php`: FDR figures, statement, remainingBalance, collection lists, cash out.
- Modify `app/Services/TransactionService.php`: return rules.
- Modify `app/Services/AccountService.php`, `app/Http/Controllers/Admin/AccountController.php`, `app/Http/Requests/Admin/StoreAccountRequest.php`: open FDR.
- Modify `app/Services/CashService.php`, `app/Services/ReportService.php`: collection list, cash book, monthly, dashboard.
- Modify `app/Http/Controllers/Admin/PartyController.php`: totals exclude FDR from savings.
- Modify views: `admin/accounts/create`, `admin/accounts/show`, `admin/parties/show`, `field/collections/create`, `field/collections/party`, `admin/reports/daily-cash-book`, `admin/dashboard/_live`; JS `resources/js/admin/accounts.js`.
- Modify `lang/en/app.php`, `lang/bn/app.php`.
- Create `tests/Feature/FdrAccountTest.php` (all FDR behaviour tests). Modify `tests/Feature/DomainInvariantsTest.php`.
- Modify `docs/ROADMAP.md`, `docs/superpowers/specs/2026-10-13-fdr-accounts-design.md` (amendment line).

---

### Task 1: Types, helpers, factory state

**Files:**
- Modify: `app/Enums/AccountType.php`, `app/Enums/TransactionType.php`, `app/Models/Account.php`, `database/factories/AccountFactory.php`, `docs/superpowers/specs/2026-10-13-fdr-accounts-design.md`
- Create: `tests/Feature/FdrAccountTest.php`
- Lang (this task only the keys used by enums' labels): `lang/en/app.php`, `lang/bn/app.php`

**Interfaces:**
- Produces: `AccountType::Fdr` (`'fdr'`, prefix `FD`, `entryTypes()` = `[InvestmentDeposit, InvestmentReturn]`, `collectionType()` = `InvestmentDeposit`); `TransactionType::collectionTypes(): list<TransactionType>` = `[LoanCollection, SavingDeposit, InvestmentDeposit]`; `TransactionType::InvestmentDeposit/InvestmentReturn` recordable, `accountType()` = Fdr, `issuesReceipt()` true only for deposit; `Account::isFdr(): bool`; `Account::factory()->fdr(?string $agreedPaybackTaka = null)`.

- [ ] **Step 1: Confirm no schema change is needed**

Use Boost `database-schema` with filter `accounts` and `transactions`; confirm `account_type` and `type` are plain strings (no DB enum). Expected: string columns.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/FdrAccountTest.php` (full setup reused by later tasks):

```php
<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\TransactionData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FdrAccountTest extends TestCase
{
    use RefreshDatabase;

    private TransactionService $service;

    private LedgerService $ledger;

    private User $accountant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = app(TransactionService::class);
        $this->ledger = app(LedgerService::class);
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->admin = User::factory()->withRole(Role::Admin)->create();
    }

    public function test_fdr_type_wiring(): void
    {
        $this->assertSame('FD', AccountType::Fdr->prefix());
        $this->assertSame([TransactionType::InvestmentDeposit, TransactionType::InvestmentReturn], AccountType::Fdr->entryTypes());
        $this->assertSame(TransactionType::InvestmentDeposit, AccountType::Fdr->collectionType());
        $this->assertSame(
            [TransactionType::LoanCollection, TransactionType::SavingDeposit, TransactionType::InvestmentDeposit],
            TransactionType::collectionTypes(),
        );
        $this->assertTrue(TransactionType::InvestmentDeposit->isRecordable());
        $this->assertTrue(TransactionType::InvestmentDeposit->issuesReceipt());
        $this->assertFalse(TransactionType::InvestmentReturn->issuesReceipt());
        $this->assertSame(AccountType::Fdr, TransactionType::InvestmentReturn->accountType());
        $this->assertTrue(Account::factory()->fdr('1200')->create()->isFdr());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(TransactionType $type, string $taka, Account $account, array $extra = [], ?User $actor = null): Transaction
    {
        return $this->service->record(new TransactionData(...[
            'type' => $type,
            'amount' => Money::fromTaka($taka),
            'date' => today(config('app.display_timezone')),
            'account' => $account,
            ...$extra,
        ]), $actor ?? $this->accountant);
    }

    /**
     * @param  callable(): mixed  $write
     */
    private function assertRejected(callable $write): void
    {
        $before = Transaction::query()->count();

        try {
            $write();
            $this->fail('Expected the write to be rejected.');
        } catch (ValidationException) {
            $this->assertSame($before, Transaction::query()->count());
        }
    }
}
```

(Unused imports `PaymentMethod`, `Carbon` are used by later tasks; remove any still unused at the end of the plan with Pint/PHPStan.)

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=FdrAccountTest`
Expected: FAIL (`Undefined constant AccountType::Fdr`).

- [ ] **Step 4: Implement**

`app/Enums/AccountType.php`: add `case Fdr = 'fdr';` and extend the three matches:

```php
// prefix()
self::Fdr => 'FD',
// entryTypes()
self::Fdr => [TransactionType::InvestmentDeposit, TransactionType::InvestmentReturn],
// collectionType()
self::Fdr => TransactionType::InvestmentDeposit,
```

`app/Enums/TransactionType.php`:
- `isRecordable()`: `self::Adjustment, self::Reversal => false,` and update the docblock to "Adjustment rows have no defined direction yet; reversals are written only by reverse()."
- `accountType()`: add `self::InvestmentDeposit, self::InvestmentReturn => AccountType::Fdr,`
- Add after `savingInTypes()`:

```php
    /**
     * Money received from a party that the field collects and the office receipts: installments,
     * savings deposits and FDR deposits.
     *
     * @return list<self>
     */
    public static function collectionTypes(): array
    {
        return [self::LoanCollection, self::SavingDeposit, self::InvestmentDeposit];
    }
```
- `issuesReceipt()`: `return in_array($this, self::collectionTypes(), true);`

`app/Models/Account.php`, after `isLoan()`:

```php
    public function isFdr(): bool
    {
        return $this->account_type === AccountType::Fdr;
    }
```

`database/factories/AccountFactory.php`, after `loan()`:

```php
    public function fdr(?string $agreedPaybackTaka = null): static
    {
        return $this->state(fn (array $attributes) => [
            'account_number' => 'FD-'.fake()->unique()->numerify('9#####'),
            'account_type' => AccountType::Fdr,
            'contract_amount' => $agreedPaybackTaka === null ? null : Money::fromTaka($agreedPaybackTaka),
        ]);
    }
```

Spec amendment: in the spec file under "Ledger", replace the figures line with: `- Computed figures only: deposited, agreed payback, paid back, remaining payback = (contract, or deposited when none is set) - returned, held = deposited - returned (no clamp).`

Lang: add `'fdr' => 'FDR'` to `accounts.types` in en (`['saving' => 'Savings', 'loan' => 'Loan', 'fdr' => 'FDR']`) and bn (`'fdr' => 'এফডিআর'`; keep the existing bn entries). The `transactions.types.investment_*` keys already exist in en; confirm they exist in bn, add if missing (`'investment_deposit' => 'এফডিআর জমা'`, `'investment_return' => 'এফডিআর ফেরত'`). Set en `investment_deposit` to `'FDR deposit'` and `investment_return` to `'FDR payback'`.

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter="FdrAccountTest|TransactionServiceTest|LangFilesTest"`
Expected: PASS (the old "InvestmentDeposit with null account is rejected" assertion still holds via `account_mismatch`).

- [ ] **Step 6: Commit**

```bash
git add app/Enums app/Models/Account.php database/factories/AccountFactory.php lang tests/Feature/FdrAccountTest.php docs/superpowers/specs
git commit -m "feat: FDR account type and ledger types"
```

---

### Task 2: LedgerService figures and shared collection lists

**Files:**
- Modify: `app/Services/LedgerService.php`
- Test: `tests/Feature/FdrAccountTest.php`

**Interfaces:**
- Consumes: Task 1 enums.
- Produces on `LedgerService`: `fdrDeposited(Account): Money`, `fdrReturned(Account): Money`, `fdrRemaining(Account): Money` (= `(contract ?? deposited) - returned`), `fdrHeld(Account): Money` (deposited - returned), `totalFdrHeld(): Money`; `remainingBalance()` returns `fdrRemaining` for Fdr; `statement()` supports Fdr (deposit in, return out, running = held).

- [ ] **Step 1: Write the failing tests** (add to `FdrAccountTest`; they need Task 3 rules to record returns, so seed the ledger with `TransactionFactory`. Check `database/factories/TransactionFactory.php` for its states; if it has no `type`/`account` override, pass attributes `['account_id' => $fdr->id, 'party_id' => $fdr->party_id, 'type' => TransactionType::InvestmentReturn, 'amount' => Money::fromTaka('300')]`.)

```php
    public function test_fdr_figures_and_statement_come_from_the_ledger(): void
    {
        $fdr = Account::factory()->fdr('1200')->create(['opened_at' => today(config('app.display_timezone'))->subDays(5)]);
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);
        $this->record(TransactionType::InvestmentDeposit, '500', $fdr);
        Transaction::factory()->create([
            'account_id' => $fdr->id, 'party_id' => $fdr->party_id,
            'type' => TransactionType::InvestmentReturn, 'amount' => Money::fromTaka('300'),
        ]);

        $this->assertSame(150000, $this->ledger->fdrDeposited($fdr)->poisha);
        $this->assertSame(30000, $this->ledger->fdrReturned($fdr)->poisha);
        $this->assertSame(120000 - 30000, $this->ledger->fdrRemaining($fdr)->poisha);
        $this->assertSame(120000, $this->ledger->fdrHeld($fdr)->poisha);
        $this->assertSame($this->ledger->fdrRemaining($fdr)->poisha, $this->ledger->remainingBalance($fdr)->poisha);
        $this->assertSame($this->ledger->fdrHeld($fdr)->poisha, $this->ledger->statement($fdr)->closing->poisha);
        $this->assertSame(120000, $this->ledger->totalFdrHeld()->poisha);
        $this->assertSame(0, $this->ledger->totalSavingBalance()->poisha);
    }

    public function test_without_an_agreed_payback_remaining_is_what_was_deposited(): void
    {
        $fdr = Account::factory()->fdr()->create();
        $this->record(TransactionType::InvestmentDeposit, '700', $fdr);

        $this->assertSame(70000, $this->ledger->fdrRemaining($fdr)->poisha);
    }

    public function test_fdr_deposits_count_as_cash_collected_by_the_officer(): void
    {
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $fdr = Account::factory()->fdr()->create();
        $this->record(TransactionType::InvestmentDeposit, '400', $fdr, ['fieldOfficer' => $officer, 'paymentMethod' => PaymentMethod::Cash]);

        $this->assertSame(40000, $this->ledger->cashInHand([$officer->id])[$officer->id]->poisha);
        $this->assertSame(40000, $this->ledger->receivedOn(today(config('app.display_timezone')))->poisha);
    }
```

Note `TransactionData` has a `paymentMethod` default of cash; passing it is harmless.

- [ ] **Step 2: Run to verify fail**

Run: `php artisan test --compact --filter=FdrAccountTest`
Expected: FAIL (`Call to undefined method fdrDeposited`).

- [ ] **Step 3: Implement in `LedgerService`**

Add after `savingCredited()`:

```php
    public function fdrDeposited(Account $account): Money
    {
        return $this->sum($account, TransactionType::InvestmentDeposit);
    }

    public function fdrReturned(Account $account): Money
    {
        return $this->sum($account, TransactionType::InvestmentReturn);
    }

    /**
     * Money the NGO still holds for the member: deposited minus paid back. Negative if more went
     * out than came in (the agreed payback can exceed the deposits).
     */
    public function fdrHeld(Account $account): Money
    {
        return $this->fdrDeposited($account)->minus($this->fdrReturned($account));
    }

    /**
     * Payback still to be paid: the agreed payback (the deposits when none is agreed yet) minus
     * what was paid back. Must reach zero before the account can close.
     */
    public function fdrRemaining(Account $account): Money
    {
        return ($account->contract_amount ?? $this->fdrDeposited($account))->minus($this->fdrReturned($account));
    }
```

Replace `remainingBalance()` body:

```php
        return match ($account->account_type) {
            AccountType::Loan => $this->loanOutstanding($account),
            AccountType::Fdr => $this->fdrRemaining($account),
            AccountType::Saving => $this->savingBalance($account),
        };
```
and its docblock: "loan outstanding, FDR remaining payback or saving balance."

In `statement()`, replace the `$columns = $account->isLoan() ? [...] : [...]` with a `match`:

```php
        $columns = match ($account->account_type) {
            AccountType::Loan => [
                TransactionType::LoanCollection->value => 'in',
                TransactionType::LoanOpeningReceived->value => 'in',
                TransactionType::LoanDisbursement->value => 'out',
                TransactionType::LoanOpeningDisbursement->value => 'out',
            ],
            AccountType::Fdr => [
                TransactionType::InvestmentDeposit->value => 'in',
                TransactionType::InvestmentReturn->value => 'out',
            ],
            AccountType::Saving => [
                ...array_fill_keys(array_map(fn (TransactionType $type): string => $type->value, TransactionType::savingInTypes()), 'in'),
                TransactionType::SavingWithdrawal->value => 'out',
            ],
        };
```
(the running-total loop stays: only loans skip subtracting `out`.)

Add near `totalSavingBalance()`:

```php
    /**
     * FDR deposits minus paybacks over every FDR account.
     */
    public function totalFdrHeld(): Money
    {
        $in = (int) $this->effectiveRows()->where('type', TransactionType::InvestmentDeposit)->sum('amount');
        $out = (int) $this->effectiveRows()->where('type', TransactionType::InvestmentReturn)->sum('amount');

        return Money::fromPoisha($in - $out);
    }
```

Replace the literal list `[TransactionType::LoanCollection, TransactionType::SavingDeposit]` with `TransactionType::collectionTypes()` in the four places (cashCollectedBy, receivedPerDay, collectionRows, and the one in `officeCash` as `[...TransactionType::collectionTypes(), TransactionType::OfficeIncome]`). Verify: `grep -n "LoanCollection, TransactionType::SavingDeposit" app/Services/LedgerService.php` prints nothing.

In `officeCash()`, `$paidOut` list becomes `[TransactionType::LoanDisbursement, TransactionType::SavingWithdrawal, TransactionType::InvestmentReturn, TransactionType::OfficeExpense]` and the docblock "cash paid out (disbursements, withdrawals, FDR paybacks, office expenses)". Update the docblock of `collectionRows`, `receivedPerDay`, `cashCollectedBy`, `lastCollectionAt`: "...loan collections, savings and FDR deposits".

Also update `CashService::handOverCollection`: replace `in_array($collection->type, [TransactionType::LoanCollection, TransactionType::SavingDeposit], true)` with `in_array($collection->type, TransactionType::collectionTypes(), true)`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter="FdrAccountTest|LedgerServiceTest|CashTest|DomainInvariantsTest"`
Expected: PASS. (If a filter name matches nothing, run `php artisan test --compact` once.)

- [ ] **Step 5: Commit**

```bash
git add app/Services tests/Feature/FdrAccountTest.php
git commit -m "feat: FDR ledger figures and shared collection types"
```

---

### Task 3: TransactionService rules and scheduled FDR collection

**Files:**
- Modify: `app/Services/TransactionService.php`, `lang/en/app.php`, `lang/bn/app.php`
- Test: `tests/Feature/FdrAccountTest.php`

**Interfaces:**
- Consumes: Task 1-2.
- Produces: FDR rule keys under `app.transactions.*`: `no_agreed_payback`, `exceeds_agreed_payback` (fields `type`, `amount`).

- [ ] **Step 1: Write failing tests** (add to `FdrAccountTest`; add `use App\Models\CollectionSchedule;` and `use App\Enums\ScheduleStatus;`)

```php
    public function test_deposit_posts_with_a_receipt_and_needs_no_cap(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();

        $deposit = $this->record(TransactionType::InvestmentDeposit, '5000', $fdr);

        $this->assertNotNull($deposit->receipt_no);
        $this->assertSame($fdr->party_id, $deposit->party_id);
        $this->assertSame(PaymentMethod::Cash, $deposit->payment_method);
    }

    public function test_return_needs_an_agreed_payback_and_stays_within_it(): void
    {
        $noContract = Account::factory()->fdr()->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $noContract);
        $this->assertRejected(fn () => $this->record(TransactionType::InvestmentReturn, '100', $noContract));

        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '300', $fdr);

        $early = $this->record(TransactionType::InvestmentReturn, '1000', $fdr); // more than deposited so far: allowed
        $this->assertNull($early->receipt_no);
        $this->assertSame(PaymentMethod::Cash, $early->payment_method);
        $this->assertRejected(fn () => $this->record(TransactionType::InvestmentReturn, '200.01', $fdr));
        $this->record(TransactionType::InvestmentReturn, '200', $fdr);
        $this->assertSame(0, $this->ledger->fdrRemaining($fdr)->poisha);
    }

    public function test_reversing_a_return_frees_room_and_a_deposit_can_be_reversed_freely(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();
        $deposit = $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);
        $return = $this->record(TransactionType::InvestmentReturn, '1200', $fdr);

        $this->service->reverse($deposit, 'wrong member', $this->admin); // held goes negative: allowed
        $this->assertSame(-120000, $this->ledger->fdrHeld($fdr)->poisha);

        $this->service->reverse($return, 'paid twice', $this->admin);
        $this->assertSame(120000, $this->ledger->fdrRemaining($fdr)->poisha);
        $this->record(TransactionType::InvestmentReturn, '1200', $fdr);
    }

    public function test_saving_types_are_rejected_on_an_fdr_account_and_vice_versa(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();
        $saving = Account::factory()->create();

        $this->assertRejected(fn () => $this->record(TransactionType::SavingDeposit, '100', $fdr));
        $this->assertRejected(fn () => $this->record(TransactionType::InvestmentDeposit, '100', $saving));
    }

    public function test_scheduled_fdr_installment_settles_the_schedule_row(): void
    {
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $fdr = Account::factory()->fdr()->create();
        $schedule = CollectionSchedule::factory()->create([
            'account_id' => $fdr->id, 'field_officer_id' => $officer->id, 'expected_amount' => Money::fromTaka('100'),
        ]);

        $this->record(TransactionType::InvestmentDeposit, '60', $fdr, ['schedule' => $schedule, 'fieldOfficer' => $officer], $officer);
        $this->assertSame(ScheduleStatus::PartiallyPaid, $schedule->refresh()->status);

        $this->record(TransactionType::InvestmentDeposit, '40', $fdr, ['schedule' => $schedule, 'fieldOfficer' => $officer], $officer);
        $this->assertSame(ScheduleStatus::Paid, $schedule->refresh()->status);
    }
```

Check the officer can record: `collections.record` permission isn't enforced by the service (policies do). If `$officer` lacks a needed permission here, use `$this->accountant` as actor and keep `fieldOfficer`.

- [ ] **Step 2: Run to verify fail**

Run: `php artisan test --compact --filter=FdrAccountTest`
Expected: FAIL (`test_return_needs...`: no rule yet, returns accepted without a contract).

- [ ] **Step 3: Implement**

In `TransactionService::create()`, directly after the `SavingMaturityCredit` block add:

```php
        if ($account && $type === TransactionType::InvestmentReturn) {
            if ($account->contract_amount === null) {
                $this->reject('type', 'no_agreed_payback');
            }

            if ($this->ledger->fdrReturned($account)->plus($data->amount)->greaterThan($account->contract_amount)) {
                $this->reject('amount', 'exceeds_agreed_payback');
            }
        }
```

Lang `app.transactions` group (next to `no_agreed_amount` / `exceeds_agreed`):
- en: `'no_agreed_payback' => 'Set the agreed payback on this account first.'`, `'exceeds_agreed_payback' => 'Total paid back would go above the agreed payback.'`
- bn: `'no_agreed_payback' => 'আগে এই হিসাবের চুক্তিকৃত ফেরতের পরিমাণ নির্ধারণ করুন।'`, `'exceeds_agreed_payback' => 'মোট ফেরত চুক্তিকৃত ফেরতের চেয়ে বেশি হয়ে যাবে।'`

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter="FdrAccountTest|TransactionServiceTest|LangFilesTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TransactionService.php lang tests/Feature/FdrAccountTest.php
git commit -m "feat: FDR deposit and payback rules"
```

---

### Task 4: Open and close FDR accounts

**Files:**
- Modify: `app/Services/AccountService.php`, `app/Http/Controllers/Admin/AccountController.php`, `app/Http/Requests/Admin/StoreAccountRequest.php`, `resources/views/admin/accounts/create.blade.php`, `resources/js/admin/accounts.js`, `lang/en/app.php`, `lang/bn/app.php`
- Test: `tests/Feature/FdrAccountTest.php`

**Interfaces:**
- Consumes: Task 1-3.
- Produces: `AccountService::openFdr(Party $party, ?Money $agreedPayback, Carbon $openedAt, User $actor): Account`; POST `admin.parties.accounts.store` with `account_type=fdr`.

- [ ] **Step 1: Write failing tests** (add to `FdrAccountTest`; add imports `App\Models\Party`, `App\Services\AccountService`)

```php
    public function test_opens_an_fdr_with_an_fd_number_and_optional_agreed_payback(): void
    {
        $party = Party::factory()->create();
        $accounts = app(AccountService::class);

        $first = $accounts->openFdr($party, Money::fromTaka('1200'), today(config('app.display_timezone')), $this->admin);
        $second = $accounts->openFdr($party, null, today(config('app.display_timezone')), $this->admin);

        $this->assertSame('FD-000001', $first->account_number);
        $this->assertSame('FD-000002', $second->account_number);
        $this->assertSame(AccountType::Fdr, $first->account_type);
        $this->assertSame(120000, $first->contract_amount->poisha);
        $this->assertNull($second->contract_amount);
    }

    public function test_agreed_payback_is_set_later_through_an_adjustment_with_a_reason(): void
    {
        $fdr = Account::factory()->fdr()->create();
        $accounts = app(AccountService::class);

        $adjustment = $accounts->adjustContractAmount($fdr, Money::fromTaka('1500'), 'agreed with member', $this->admin);

        $this->assertNull($adjustment->old_amount);
        $this->assertSame(150000, $fdr->refresh()->contract_amount->poisha);
    }

    public function test_close_needs_remaining_payback_zero_or_an_admin_override(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $accounts = app(AccountService::class);
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        try {
            $accounts->close($fdr, null, $manager);
            $this->fail('Expected rejection.');
        } catch (ValidationException) {
            $this->assertTrue($fdr->refresh()->isActive());
        }

        $this->record(TransactionType::InvestmentReturn, '1200', $fdr);
        $this->assertFalse($accounts->close($fdr, null, $manager)->isActive());
    }

    public function test_open_form_posts_an_fdr_account(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.parties.accounts.store', $party), [
                'account_type' => 'fdr',
                'contract_amount' => '1,200',
                'opened_at' => today(config('app.display_timezone'))->toDateString(),
            ])
            ->assertRedirect();

        $account = Account::query()->where('party_id', $party->id)->firstOrFail();
        $this->assertSame(AccountType::Fdr, $account->account_type);
        $this->assertSame(120000, $account->contract_amount->poisha);
    }
```

Open form tests need `withoutVite()` if the create page is rendered; this one only posts. If any later feature test renders a page, call `$this->withoutVite();` first (project note).

- [ ] **Step 2: Run to verify fail**

Run: `php artisan test --compact --filter=FdrAccountTest`
Expected: FAIL (`openFdr` undefined).

- [ ] **Step 3: Implement**

`AccountService`, after `openSaving()`:

```php
    /**
     * Open an FDR account with the next FD- number; `$agreedPayback` is the optional amount the
     * office agreed to pay back (set later through an adjustment when unknown now).
     */
    public function openFdr(Party $party, ?Money $agreedPayback, Carbon $openedAt, User $actor): Account
    {
        return DB::transaction(fn (): Account => $this->create($party, AccountType::Fdr, $agreedPayback, $openedAt, $actor));
    }
```
Update the `adjustContractAmount` docblock: "Change a loan's contract amount or set/change a saving's agreed amount or an FDR's agreed payback". Update `close()` docblock: "A non-zero loan outstanding, FDR remaining payback or saving balance". (`remainingBalance` already branches.)

`AccountController::store` match: add before `default`:

```php
            $request->accountType() === AccountType::Fdr => $this->accounts->openFdr($party, $request->agreedAmount(), $request->openedAt(), $this->actor()),
```
`StoreAccountRequest::agreedAmount` docblock: "Saving or FDR: the optional agreed amount (end-of-term amount or agreed payback)."

`create.blade.php`:
- icon class array: add `'bi-bank' => $type->value === 'fdr'`.
- wrap the saving fieldset: `<div data-saving-fields data-label-saving="{{ __('app.accounts.agreed_amount') }} (৳)" data-label-fdr="{{ __('app.accounts.agreed_payback') }} (৳)">`.

`resources/js/admin/accounts.js` in `initTypeToggle`, inside `sync` after the two `toggle` calls and with `isLoan` computed:

```js
        const type = form.querySelector('input[name="account_type"]:checked')?.value;
        const agreedLabel = savingFields.querySelector('label[for="contract_amount"]');
        if (agreedLabel) {
            agreedLabel.textContent = savingFields.dataset[type === 'fdr' ? 'labelFdr' : 'labelSaving'];
        }
```
(If `x-form.input` renders the label differently, read `resources/views/components/form/input.blade.php` and target its label element.) Update the file header comment to mention FDR.

Lang `accounts`: en `'agreed_payback' => 'Agreed payback'`, bn `'agreed_payback' => 'চুক্তিকৃত ফেরত'`. In en add `'agreed_amount_hint_fdr' => ...` only if the hint is changed; otherwise reuse the saving hint (it reads "what the member gets at the end of the term", true for FDR).

- [ ] **Step 4: Run tests and build**

Run: `php artisan test --compact --filter="FdrAccountTest|AccountTest|LangFilesTest"` then `npm run build`
Expected: PASS; build succeeds.

- [ ] **Step 5: Commit**

```bash
git add app resources lang tests/Feature/FdrAccountTest.php
git commit -m "feat: open and close FDR accounts"
```

---

### Task 5: Account, party and field screens

**Files:**
- Modify: `app/Http/Controllers/Admin/AccountController.php`, `app/Http/Controllers/Admin/PartyController.php`, `resources/views/admin/accounts/show.blade.php`, `resources/views/admin/parties/show.blade.php`, `resources/views/field/collections/create.blade.php`, `resources/views/field/collections/party.blade.php`, `lang/en/app.php`, `lang/bn/app.php`
- Test: `tests/Feature/FdrAccountTest.php`

**Interfaces:**
- Consumes: Tasks 1-4.
- Produces lang keys: `accounts.deposited`, `accounts.paid_back`, `accounts.remaining_payback`, `accounts.balance_label.{saving,loan,fdr}`, `accounts.agreed_label.{saving,loan,fdr}`, `accounts.statement_in.fdr`, `statement_out.fdr`, `statement_running.fdr`.

- [ ] **Step 1: Write failing tests** (add `$this->withoutVite();` to each; add imports as needed)

```php
    public function test_account_page_shows_fdr_figures_and_the_entry_form(): void
    {
        $this->withoutVite();
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        $this->actingAs($this->admin)->get(route('admin.accounts.show', $fdr))
            ->assertOk()
            ->assertSee(__('app.accounts.deposited'))
            ->assertSee(__('app.accounts.agreed_payback'))
            ->assertSee(__('app.accounts.paid_back'))
            ->assertSee(__('app.transactions.types.investment_return'));
    }

    public function test_fdr_never_counts_in_the_party_saving_total(): void
    {
        $this->withoutVite();
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        $this->actingAs($this->admin)->get(route('admin.parties.show', $fdr->party))
            ->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['saving']->isZero() && $totals['loan']->isZero());
    }

    public function test_statement_page_for_fdr_uses_neutral_labels(): void
    {
        $this->withoutVite();
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        $this->actingAs($this->admin)->get(route('admin.accounts.statement', $fdr))
            ->assertOk()
            ->assertSee(__('app.accounts.statement_out.fdr'));
    }
```

- [ ] **Step 2: Run to verify fail**

Run: `php artisan test --compact --filter=FdrAccountTest`
Expected: FAIL (missing translation key output / totals wrong).

- [ ] **Step 3: Implement**

Lang, en `accounts` group (and matching bn):
- en: `'deposited' => 'Deposited'`, `'paid_back' => 'Paid back'`, `'remaining_payback' => 'Remaining payback'`, `'balance_label' => ['saving' => 'Current balance', 'loan' => 'Outstanding', 'fdr' => 'Remaining payback']`, `'agreed_label' => ['saving' => 'Agreed amount at end of term', 'loan' => 'Contract amount', 'fdr' => 'Agreed payback']`; change `statement_in` / `statement_out` / `statement_running` arrays to add `'fdr' => 'Deposit'` / `'fdr' => 'Paid back'` / `'fdr' => 'Held'`.
- bn: `'deposited' => 'জমা'`, `'paid_back' => 'ফেরত দেওয়া হয়েছে'`, `'remaining_payback' => 'ফেরত বাকি'`, `balance_label` => `['saving' => 'বর্তমান জমা', 'loan' => 'বকেয়া', 'fdr' => 'ফেরত বাকি']` (reuse the existing bn wording for saving/loan: copy from the bn `current_balance` and `outstanding` values so labels stay consistent), `agreed_label` => saving: existing bn `agreed_amount` value, loan: existing bn `contract_amount` value, fdr: `'চুক্তিকৃত ফেরত'`; statement arrays add `'fdr' => 'জমা'` / `'ফেরত'` / `'জমা আছে'`.

`AccountController::show`: replace the `'received'` entry and add `paidBack`:

```php
            'received' => match ($account->account_type) {
                AccountType::Loan => $ledger->loanReceived($account),
                AccountType::Fdr => $ledger->fdrDeposited($account),
                AccountType::Saving => $ledger->savingCredited($account),
            },
            'paidBack' => $account->isFdr() ? $ledger->fdrReturned($account) : Money::zero(),
```
(import `App\Support\Money` if missing.) The `entryTypes()` private method needs no change (FDR types default to `true`).

`show.blade.php`:
- line ~75: `{{ __('app.accounts.balance_label.'.$kind) }}`.
- In the breakdown block, insert between the `@if ($account->isLoan()) ... @else` branches:

```blade
                                    @elseif ($account->isFdr())
                                        {{ __('app.accounts.deposited') }}: {{ $received->format() }}
                                        @if ($agreed)
                                            · {{ __('app.accounts.agreed_payback') }}: {{ $agreed->format() }}
                                        @endif
                                        · {{ __('app.accounts.paid_back') }}: {{ $paidBack->format() }}
```
- line ~110 label: `{{ __('app.accounts.agreed_label.'.$kind) }}`.
- The `text-danger` class on the balance stays loan-only.

`PartyController::show` totals:

```php
                'saving' => Money::fromPoisha($party->accounts->filter(fn (Account $account): bool => $account->account_type === AccountType::Saving)->sum(fn (Account $account): int => $balances[$account->id]->poisha)),
```
(import `App\Enums\AccountType`; keep `loan` as is.)

`parties/show.blade.php` card label: `{{ $account->isLoan() ? __('app.accounts.outstanding') : ($account->isFdr() ? __('app.accounts.remaining_payback') : __('app.accounts.balance')) }}`.

`field/collections/create.blade.php` line ~23 and `party.blade.php` line ~47: same nested ternary on `$schedule->account` (loan → `outstanding`, fdr → `remaining_payback`, else `balance`).

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter="FdrAccountTest|PartyTest|FieldCollection|LangFilesTest|QueryCountTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app resources lang tests/Feature/FdrAccountTest.php
git commit -m "feat: FDR on account, party and field screens"
```

---

### Task 6: Reports (cash book, monthly, dashboard)

**Files:**
- Modify: `app/Services/ReportService.php`, `resources/views/admin/reports/daily-cash-book.blade.php`, `resources/views/admin/reports/monthly.blade.php` (only if it hard-codes types; check with `grep -n "saving_\|loan_" resources/views/admin/reports/monthly.blade.php`), `resources/views/admin/dashboard/_live.blade.php`, `lang/en/app.php`, `lang/bn/app.php`
- Test: `tests/Feature/FdrAccountTest.php`

**Interfaces:**
- Produces: `dailyCashBook()` day rows and totals gain `investment_deposit`, `investment_return`; dashboard figures gain `fdr_held: Money`; monthly `received` includes FDR deposits, `paid_out` includes FDR paybacks.

- [ ] **Step 1: Write failing tests** (add imports `App\Services\ReportService`)

```php
    public function test_cash_book_and_monthly_include_fdr_flows(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);
        $this->record(TransactionType::InvestmentReturn, '300', $fdr);
        $day = today(config('app.display_timezone'));
        $reports = app(ReportService::class);

        $book = $reports->dailyCashBook($day, $day);
        $this->assertSame(100000, $book['totals']['investment_deposit']->poisha);
        $this->assertSame(30000, $book['totals']['investment_return']->poisha);
        $this->assertSame(70000, $book['closing']->poisha);

        $month = $reports->monthly($day);
        $this->assertSame(100000, $month['received']->poisha);
        $this->assertSame(30000, $month['paid_out']->poisha);
        $this->assertSame(70000, $month['closing']->poisha);
    }

    public function test_dashboard_shows_total_fdr_held(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        $figures = app(ReportService::class)->dashboard(today(config('app.display_timezone')));

        $this->assertSame(100000, $figures['fdr_held']->poisha);
        $this->assertSame(0, $figures['saving_balance']->poisha);
    }
```

- [ ] **Step 2: Run to verify fail**

Run: `php artisan test --compact --filter=FdrAccountTest`
Expected: FAIL (`Undefined array key "investment_deposit"`).

- [ ] **Step 3: Implement**

`ReportService`:
- `CASH_BOOK_TYPES` = `['office_expense', 'office_income', 'loan_collection', 'loan_disbursement', 'saving_deposit', 'saving_withdrawal', 'investment_deposit', 'investment_return']`; update the `dailyCashBook` return docblock to list the two new keys.
- `moneyIn()` list: `['loan_collection', 'saving_deposit', 'investment_deposit', 'office_income']`.
- `moneyOut()` list: `['loan_disbursement', 'saving_withdrawal', 'investment_return', 'office_expense']`.
- `monthly()`: 

```php
            'received' => Money::fromPoisha(array_sum(array_map(fn (TransactionType $type): int => $get($type)->poisha, TransactionType::collectionTypes()))),
            'paid_out' => $get(TransactionType::LoanDisbursement)->plus($get(TransactionType::SavingWithdrawal))->plus($get(TransactionType::InvestmentReturn)),
```
- `dashboard()`: add `'fdr_held' => $this->ledger->totalFdrHeld(),` after `saving_balance`; extend the docblock shape `fdr_held: Money`.

Views:
- `daily-cash-book.blade.php`: `$columns` gains `'investment_deposit', 'investment_return'` before the closing bracket.
- `_live.blade.php`: standing array gets `'fdr_held' => $figures['fdr_held'],` and the column class `col-md-4` becomes `col-md-3`.
- If `monthly.blade.php` lists types explicitly, add the two FDR types the same way as saving (grep above); if it loops `$report['totals']`, nothing to do.

Lang (`reports.cash_book` and `dash`), en: `'investment_deposit' => 'FDR deposit'`, `'investment_return' => 'FDR payback'`, `dash.fdr_held => 'Held for FDR'`; bn: `'এফডিআর জমা'`, `'এফডিআর ফেরত'`, `'এফডিআরে জমা আছে'`. Update the cash book `note` in en and bn to mention "FDR deposits" (+) and "FDR paybacks" (−).

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter="FdrAccountTest|MonthlyReportTest|DashboardTest|ReportTest|CashBook|LangFilesTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app resources lang tests/Feature/FdrAccountTest.php
git commit -m "feat: FDR in cash book, monthly report and dashboard"
```

---

### Task 7: Invariants, roadmap, full verification

**Files:**
- Modify: `tests/Feature/DomainInvariantsTest.php`, `docs/ROADMAP.md`

- [ ] **Step 1: Extend the rebuild test**

In `test_every_ledger_figure_rebuilds_from_raw_transaction_rows`, after the saving block and before the loan block add:

```php
        $fdr = Account::factory()->fdr('1500')->create(['opened_at' => '2026-10-01']);
        $record(TransactionType::InvestmentDeposit, '1000', '2026-10-03', $fdr, ['fieldOfficer' => $officer]);
        $record(TransactionType::InvestmentDeposit, '200', '2026-10-05', $fdr);
        $record(TransactionType::InvestmentReturn, '300', '2026-10-10', $fdr);
```
In the rebuild section:
- change `$cashCollected` types to `[LoanCollection, SavingDeposit, InvestmentDeposit]` values (use `array_map(fn ($t) => $t->value, TransactionType::collectionTypes())`).
- add:

```php
        $fdrIn = $sum($effective()->where('account_id', $fdr->id)->where('type', TransactionType::InvestmentDeposit->value));
        $fdrOut = $sum($effective()->where('account_id', $fdr->id)->where('type', TransactionType::InvestmentReturn->value));
        $this->assertSame(120000, $fdrIn);
        $this->assertSame($fdrIn, $ledger->fdrDeposited($fdr)->poisha);
        $this->assertSame($fdrOut, $ledger->fdrReturned($fdr)->poisha);
        $this->assertSame($fdrIn - $fdrOut, $ledger->statement($fdr)->closing->poisha);
        $this->assertSame($fdr->contract_amount->poisha - $fdrOut, $ledger->fdrRemaining($fdr)->poisha);
        $this->assertSame($fdrIn - $fdrOut, $ledger->totalFdrHeld()->poisha);
```
- row count `assertCount(16, ...)` becomes `19`.
- The `cashInHand` assertion formula `$cashCollected - 40000` stays correct (the 400 handover is unchanged).

- [ ] **Step 2: Run the full suite and checks**

Run:
```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```
Expected: all tests pass, Pint clean, PHPStan 0 errors. Fix any unused imports left in `FdrAccountTest`. If `PermissionMatrixTest` or `QueryCountTest` fail, read the failure: no new routes were added, so the likely cause is a page-level N+1 on the account show page (add `->with(...)` in the controller) rather than a matrix entry.

- [ ] **Step 3: Tick the roadmap**

In `docs/ROADMAP.md` change `- [ ] FDR accounts (brainstorm first): ...` to `- [x]` and add a plan sub-bullet:

```
  - Plan: see docs/superpowers/specs/2026-10-13-fdr-accounts-design.md and docs/superpowers/plans/2026-10-13-fdr-accounts.md.
```
Append handoff line:

```
- 2026-10-13: FDR accounts: `AccountType::Fdr` (FD-), `investment_deposit` (in, cash, receipt, collectable through plans/field) and `investment_return` (out, <= agreed payback, needs it set via adjustment). `TransactionType::collectionTypes()` is now the one list for "money received from a party" (use it for any new collection query). `LedgerService::fdrDeposited/fdrReturned/fdrHeld/fdrRemaining/totalFdrHeld`; remaining payback = agreed payback (deposits when none set) minus returned and must be 0 to close. Cash book has FDR deposit/payback columns, dashboard "held for FDR". FDR never counts in party saving totals. No migration. Not browser-verified.
```

- [ ] **Step 4: Commit**

```bash
git add tests docs
git commit -m "test: FDR invariants; roadmap"
```

---

## Self-Review

- Spec coverage: ledger types/rules (T1, T3), figures (T2), collection plan/field reuse + `collectionTypes()` (T1-T2, T3 scheduled test), open/adjust/close (T4), UI screens (T4-T5), cash book/monthly/dashboard (T6), tests incl. invariants, lang parity, query count (T5-T7), roadmap (T7). Non-goals untouched.
- Placeholders: none left; Task 5/6 view edits give the exact snippets and anchors; the two "check the file" notes (`x-form.input` label, `monthly.blade.php` loop) name a concrete grep/read.
- Type consistency: `fdrDeposited/fdrReturned/fdrHeld/fdrRemaining/totalFdrHeld`, `collectionTypes()`, `openFdr`, `isFdr`, factory `fdr()`, lang keys `agreed_payback`, `deposited`, `paid_back`, `remaining_payback`, `no_agreed_payback`, `exceeds_agreed_payback`, `balance_label`, `agreed_label` match across tasks.
