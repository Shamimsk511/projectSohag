<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashService;
use App\Services\LedgerService;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\TransactionData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Rules from CLAUDE.md and docs/DOMAIN_RULES.md that must hold across the whole app, not per feature.
 */
class DomainInvariantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables whose rows are never changed after insert (the models throw; raw queries would bypass that).
     */
    private const IMMUTABLE_TABLES = ['transactions', 'audit_logs', 'cash_handovers', 'daily_closings', 'opening_balances'];

    /**
     * Every column holding money (poisha).
     */
    private const MONEY_COLUMNS = [
        'transactions' => ['amount'],
        'accounts' => ['contract_amount'],
        'account_adjustments' => ['old_amount', 'new_amount'],
        'collection_plans' => ['installment_amount'],
        'collection_schedules' => ['expected_amount'],
        'cash_handovers' => ['amount'],
        'opening_balances' => ['amount'],
    ];

    public function test_no_interest_profit_or_compliance_wording_in_the_product(): void
    {
        $hits = $this->sourceLines(
            ['app', 'database', 'routes', 'resources/views', 'resources/js', 'lang/en/app.php', 'lang/bn/app.php', 'config/adminlte.php'],
            '/\b(interest|profit)|সুদ|মুনাফা|shariah[- ]compliant/iu',
        );

        $this->assertSame([], $hits, 'The software records agreed amounts; it never names or computes interest or profit.');
    }

    public function test_schema_has_no_interest_columns_no_stored_balances_and_integer_money(): void
    {
        $forbidden = [];
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getColumnListing($table['name']) as $column) {
                if (preg_match('/interest|profit|balance|outstanding|received|collected/i', $column)) {
                    $forbidden[] = "{$table['name']}.{$column}";
                }
            }
        }
        $this->assertSame([], $forbidden, 'Balances are computed from transactions, never stored.');

        foreach (self::MONEY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertContains(Schema::getColumnType($table, $column), ['integer', 'bigint'], "{$table}.{$column} must hold integer poisha");
            }
        }
    }

    public function test_only_the_transaction_service_creates_ledger_rows_and_nothing_bypasses_immutability(): void
    {
        $creators = array_filter(
            $this->sourceLines(['app'], '/Transaction::(create|forceCreate|insert|query\(\)->(create|insert))|new Transaction\b|->transactions\(\)->(create|save)/'),
            fn (string $hit): bool => ! str_starts_with($hit, 'app/Services/TransactionService.php:'),
        );
        $this->assertSame([], array_values($creators), 'TransactionService is the only writer of ledger rows.');

        $tables = implode('|', self::IMMUTABLE_TABLES);
        $bypasses = [];
        foreach ($this->sourceStatements(['app', 'database/seeders', 'routes']) as $where => $statement) {
            $rawTable = preg_match("/DB::table\\(['\"]({$tables})['\"]\\)/", $statement) === 1
                && preg_match('/->(insert|update|delete|upsert|truncate|increment|decrement)\w*\(/', $statement) === 1;
            $massWrite = preg_match('/\b(Transaction|AuditLog|CashHandover|DailyClosing|OpeningBalance)::\w[^;]*->(update|delete|forceDelete|upsert|truncate|increment|decrement)\(/s', $statement) === 1;
            if ($rawTable || $massWrite) {
                $bypasses[] = $where;
            }
        }
        $this->assertSame([], $bypasses, 'Query-builder writes would skip the model guards on immutable tables.');
    }

    public function test_every_ledger_figure_rebuilds_from_raw_transaction_rows(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $admin = User::factory()->withRole(Role::Admin)->create();
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $service = app(TransactionService::class);
        $ledger = app(LedgerService::class);

        $saving = Account::factory()->create(['opened_at' => '2026-10-01', 'contract_amount' => Money::fromTaka('2000')]);
        $loan = Account::factory()->loan('1000')->create(['opened_at' => '2026-10-01']);
        $schedule = CollectionSchedule::factory()->create([
            'account_id' => $loan->id, 'field_officer_id' => $officer->id, 'expected_amount' => Money::fromTaka('300'),
        ]);

        $record = fn (TransactionType $type, string $taka, string $date, ?Account $account = null, array $extra = [], ?User $actor = null): Transaction => $service->record(
            new TransactionData(...['type' => $type, 'amount' => Money::fromTaka($taka), 'date' => Carbon::parse($date, config('app.display_timezone')), 'account' => $account, ...$extra]),
            $actor ?? $admin,
        );

        $record(TransactionType::SavingOpeningBalance, '300', '2026-10-01', $saving);
        $deposit = $record(TransactionType::SavingDeposit, '500', '2026-10-02', $saving, ['fieldOfficer' => $officer]);
        $record(TransactionType::SavingDeposit, '250.50', '2026-10-05', $saving, ['paymentMethod' => PaymentMethod::MobileBanking]);
        $record(TransactionType::SavingWithdrawal, '100', '2026-10-06', $saving);
        $service->reverse($deposit, 'Entered on the wrong member', $admin);
        $record(TransactionType::SavingDeposit, '500', '2026-10-12', $saving, ['fieldOfficer' => $officer]);
        $record(TransactionType::SavingMaturityCredit, '949.50', '2026-10-12', $saving);

        $fdr = Account::factory()->fdr('1500')->create(['opened_at' => '2026-10-01']);
        $record(TransactionType::InvestmentDeposit, '1000', '2026-10-03', $fdr, ['fieldOfficer' => $officer]);
        $record(TransactionType::InvestmentDeposit, '200', '2026-10-05', $fdr);
        $record(TransactionType::InvestmentReturn, '300', '2026-10-10', $fdr);

        $record(TransactionType::LoanDisbursement, '800', '2026-10-01', $loan);
        $collection = $record(TransactionType::LoanCollection, '200', '2026-10-12', $loan, ['schedule' => $schedule, 'fieldOfficer' => $officer, 'clientUuid' => 'a7f1c1b2-0000-4000-8000-000000000001'], $officer);
        $record(TransactionType::LoanCollection, '200', '2026-10-12', $loan, ['schedule' => $schedule, 'fieldOfficer' => $officer, 'clientUuid' => 'a7f1c1b2-0000-4000-8000-000000000001'], $officer);
        $record(TransactionType::LoanCollection, '150', '2026-10-12', $loan, ['schedule' => $schedule, 'fieldOfficer' => $officer], $officer);
        $service->reverse($collection, 'Counted twice', $admin);
        $record(TransactionType::LoanCollection, '900', '2026-10-12', $loan, ['overrideReason' => 'Settled early with an agreed extra']);

        $income = $record(TransactionType::OfficeIncome, '1000', '2026-10-03');
        $record(TransactionType::OfficeIncome, '75', '2026-10-04');
        $record(TransactionType::OfficeExpense, '40.25', '2026-10-04');
        $service->reverse($income, 'Duplicate entry', $admin);

        app(CashService::class)->recordHandover($officer, Money::fromTaka('400'), Carbon::parse('2026-10-12', config('app.display_timezone')), null, $admin);

        // Independent rebuild from raw rows: reversed originals and reversal rows carry no weight.
        $reversedIds = DB::table('transactions')->whereNotNull('reverses_transaction_id')->pluck('reverses_transaction_id')->all();
        $effective = fn () => DB::table('transactions')->where('type', '!=', TransactionType::Reversal->value)->whereNotIn('id', $reversedIds);
        $sum = fn ($query): int => (int) $query->sum('amount');

        $savingIn = [TransactionType::SavingDeposit->value, TransactionType::SavingOpeningBalance->value, TransactionType::SavingMaturityCredit->value];
        $savingBalance = $sum($effective()->where('account_id', $saving->id)->whereIn('type', $savingIn))
            - $sum($effective()->where('account_id', $saving->id)->where('type', TransactionType::SavingWithdrawal->value));
        $loanReceived = $sum($effective()->where('account_id', $loan->id)->where('type', TransactionType::LoanCollection->value));
        $cashCollected = $sum($effective()->where('field_officer_id', $officer->id)->where('payment_method', PaymentMethod::Cash->value)
            ->whereIn('type', array_map(fn ($t) => $t->value, TransactionType::collectionTypes())));

        $this->assertSame(190000, $savingBalance); // 300 + 250.50 - 100 + 500 + 949.50, the reversed 500 left out
        $this->assertSame($savingBalance, $ledger->savingBalance($saving)->poisha);
        $this->assertSame($savingBalance, $ledger->statement($saving)->closing->poisha);
        $this->assertSame(105000, $loanReceived); // 150 + 900; the duplicate uuid and the reversed 200 add nothing
        $this->assertSame($loanReceived, $ledger->loanReceived($loan)->poisha);
        $this->assertSame($loanReceived, $ledger->statement($loan)->closing->poisha);
        $this->assertSame($loan->contract_amount->poisha - $loanReceived, $ledger->loanOutstanding($loan)->poisha);
        $this->assertSame(
            $sum($effective()->where('schedule_id', $schedule->id)),
            $ledger->collectedForSchedules([$schedule->id])[$schedule->id]->poisha,
        );
        $this->assertSame($cashCollected - 40000, $ledger->cashInHand([$officer->id])[$officer->id]->poisha);

        $fdrIn = $sum($effective()->where('account_id', $fdr->id)->where('type', TransactionType::InvestmentDeposit->value));
        $fdrOut = $sum($effective()->where('account_id', $fdr->id)->where('type', TransactionType::InvestmentReturn->value));
        $this->assertSame(120000, $fdrIn);
        $this->assertSame($fdrIn, $ledger->fdrDeposited($fdr)->poisha);
        $this->assertSame($fdrOut, $ledger->fdrReturned($fdr)->poisha);
        $this->assertSame($fdrIn - $fdrOut, $ledger->statement($fdr)->closing->poisha);
        $this->assertSame($fdr->contract_amount->poisha - $fdrOut, $ledger->fdrRemaining($fdr)->poisha);
        $this->assertSame($fdrIn - $fdrOut, $ledger->totalFdrHeld()->poisha);

        $office = $ledger->officeTotals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertSame($sum($effective()->whereNull('account_id')->where('type', TransactionType::OfficeIncome->value)), $office['income']->poisha);
        $this->assertSame($sum($effective()->whereNull('account_id')->where('type', TransactionType::OfficeExpense->value)), $office['expense']->poisha);

        // Row-level rules: positive amounts, one reversal per original, one audit row per ledger row.
        $rows = Transaction::query()->get();
        $this->assertCount(19, $rows);
        $this->assertTrue($rows->every(fn (Transaction $row): bool => $row->amount->isPositive()));
        $this->assertSame(count($reversedIds), count(array_unique($reversedIds)));
        foreach ($rows as $row) {
            $this->assertSame(1, AuditLog::query()
                ->where('auditable_type', $row->getMorphClass())
                ->where('auditable_id', $row->id)
                ->whereIn('action', ['transaction.recorded', 'transaction.reversed'])
                ->count(), "Transaction {$row->id} must have exactly one audit row");
        }
    }

    /**
     * Matching lines as "path:line", over files (or directories) relative to the project root.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function sourceLines(array $paths, string $pattern): array
    {
        $hits = [];
        foreach ($this->sourceFiles($paths) as $relative => $contents) {
            foreach (preg_split('/\R/', $contents) ?: [] as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = $relative.':'.($number + 1);
                }
            }
        }

        return $hits;
    }

    /**
     * PHP statements (split on ";") keyed by "path#n", so multi-line query chains are seen whole.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function sourceStatements(array $paths): array
    {
        $statements = [];
        foreach ($this->sourceFiles($paths) as $relative => $contents) {
            if (str_ends_with($relative, '.php')) {
                foreach (explode(';', $contents) as $index => $statement) {
                    $statements["{$relative}#{$index}"] = $statement;
                }
            }
        }

        return $statements;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function sourceFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $absolute = base_path($path);
            $found = is_dir($absolute)
                ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS)))
                : [new SplFileInfo($absolute)];
            foreach ($found as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && preg_match('/\.(php|js)$/', $file->getFilename())) {
                    $files[ltrim(str_replace(base_path(), '', $file->getPathname()), '/')] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
