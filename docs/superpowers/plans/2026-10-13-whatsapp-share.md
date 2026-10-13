# Manual WhatsApp Share Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One-tap WhatsApp reminders, receipts and disbursement/withdrawal notices via `wa.me` links, with each tap logged so lists show "WhatsApp opened HH:MM · name".

**Architecture:** `App\Support\WhatsApp::link()` builds the URL; `App\Services\WhatsAppService` builds the message texts (from lang + ledger figures), records taps in an immutable `whatsapp_logs` table and returns today's reminder labels per schedule row. Three anonymous Blade components (`x-whatsapp.remind`, `x-whatsapp.receipt`, `x-whatsapp.notice`) render a plain `<a target="_blank">` only when the phone is valid and the user is allowed; a tiny shared JS module posts the tap to `admin.whatsapp.log` / `field.whatsapp.log` (best effort) and shows the returned label.

**Tech Stack:** Laravel 13, Blade anonymous components, vanilla JS (`fetch` keepalive), PHPUnit.

**Spec:** docs/superpowers/specs/2026-10-13-whatsapp-share-design.md

## Global Constraints

- No WhatsApp API, no automatic or bulk sending, no SMS, no new dependency.
- Neutral wording only: never interest/profit/সুদ/মুনাফা (DomainInvariantsTest scans views + lang). Use "contract amount", "remaining", "balance".
- UI and message text only from `lang/{en,bn}/app.php` (new group `whatsapp`); en/bn parity (`LangFilesTest`). Amounts via `Money::format()`, dates `Numerals::localize($date->format('d/m/Y'))`.
- Valid phone = `^01[3-9][0-9]{8}$`; link = `https://wa.me/880` + phone without leading `0` + `?text=` + `rawurlencode(text)`. Invalid/missing phone -> no button.
- Permissions: reminder = `CollectionSchedulePolicy::collect` or `viewAny`; receipt = `TransactionPolicy::viewReceipt`; notice = `ledger.post` + can view the account + type `loan_disbursement`/`saving_withdrawal`. Checked when rendering and again on the log endpoint.
- `whatsapp_logs` is immutable (updating/deleting throw), `created_at` only; not a financial write, so no audit row.
- Logging is best effort: link opens even if the POST fails; no offline queue; no JS = link works, nothing logged.
- "Today" for badges = Dhaka day (`config('app.display_timezone')`); `created_at` stored UTC.
- No N+1: badge labels are one query per page (`reminderLabels`); components only use relations already loaded by their pages (`QueryCountTest` must stay green).
- Field entry stays light (vanilla JS only). Do not commit: the working tree holds other uncommitted work; leave changes uncommitted.
- Done = `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse` (0 errors), `npm run build`.

---

### Task 1: Link helper, log table, service, policies, log endpoint

**Files:**
- Create: `app/Support/WhatsApp.php`, `app/Enums/WhatsAppKind.php`, `app/Models/WhatsAppLog.php`, `database/migrations/2026_10_13_120000_create_whatsapp_logs_table.php`, `app/Services/WhatsAppService.php`, `app/Http/Controllers/WhatsAppLogController.php`, `app/Http/Requests/LogWhatsAppRequest.php`
- Modify: `app/Policies/CollectionSchedulePolicy.php`, `app/Policies/TransactionPolicy.php`, `routes/web.php`, `lang/en/app.php`, `lang/bn/app.php`, `tests/Feature/PermissionMatrixTest.php`, `tests/Feature/DomainInvariantsTest.php` (only if its immutable-table list must include the new table; see Step 9)
- Test: `tests/Unit/Support/WhatsAppTest.php`, `tests/Feature/WhatsAppShareTest.php`

**Interfaces:**
- Produces:
  - `WhatsApp::link(?string $phone, string $text): ?string`
  - `enum WhatsAppKind: string { Reminder = 'reminder'; Receipt = 'receipt'; Notice = 'notice'; }`
  - `WhatsAppService::reminderText(CollectionSchedule $schedule, Money $remaining): string` (uses `$schedule->party`, `$schedule->account`)
  - `WhatsAppService::receiptText(Transaction $transaction): string` (uses `$transaction->party`, `$transaction->account`)
  - `WhatsAppService::noticeText(Transaction $transaction, Account $account): ?string` (null for other types)
  - `WhatsAppService::record(WhatsAppKind $kind, Party $party, ?CollectionSchedule $schedule, ?Transaction $transaction, User $actor): WhatsAppLog`
  - `WhatsAppService::label(WhatsAppLog $log): string` ("WhatsApp opened HH:MM · name")
  - `WhatsAppService::reminderLabels(array $scheduleIds, Carbon $day): array<int, string>` (latest reminder per schedule on that Dhaka day)
  - Policies: `CollectionSchedulePolicy::remind(User, CollectionSchedule): bool`, `TransactionPolicy::shareNotice(User, Transaction): bool`
  - Routes: `admin.whatsapp.log`, `field.whatsapp.log` (POST, JSON in, `{label}` out)
  - Lang `app.whatsapp.*`: send, remind, opened, reminder, receipt, remaining.{loan,saving,fdr}, disbursed, installment_line, withdrawn

- [ ] **Step 1: Unit test for the link** — `tests/Unit/Support/WhatsAppTest.php`

```php
<?php

namespace Tests\Unit\Support;

use App\Support\WhatsApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhatsAppTest extends TestCase
{
    public function test_link_uses_the_international_number_and_encodes_the_text(): void
    {
        $this->assertSame(
            'https://wa.me/8801711223344?text=%E0%A6%B9%E0%A7%8D%E0%A6%AF%E0%A6%BE%E0%A6%81%20%E0%A7%B3%20%E0%A7%AB%E0%A7%A6%E0%A7%A6%0Aok',
            WhatsApp::link('01711223344', "হ্যাঁ ৳ ৫০০\nok"),
        );
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function invalidPhones(): array
    {
        return ['null' => [null], 'empty' => [''], 'short' => ['0171122334'], 'landline' => ['0211223344'], 'already international' => ['8801711223344']];
    }

    #[DataProvider('invalidPhones')]
    public function test_no_link_without_a_valid_mobile_number(?string $phone): void
    {
        $this->assertNull(WhatsApp::link($phone, 'hi'));
    }
}
```

- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Unit/Support/WhatsAppTest.php` -> class not found.

- [ ] **Step 3: `app/Support/WhatsApp.php`**

```php
<?php

namespace App\Support;

/**
 * Click-to-chat links (https://wa.me): the member's phone with the message already typed. Staff press Send in
 * WhatsApp themselves; nothing is sent by the system.
 */
final class WhatsApp
{
    /** Bangladeshi mobile numbers as stored on parties (01XXXXXXXXX). */
    private const string MOBILE = '/^01[3-9][0-9]{8}$/';

    /**
     * Link that opens a chat with this member and the text filled in, or null when the phone is not a mobile number.
     */
    public static function link(?string $phone, string $text): ?string
    {
        if ($phone === null || preg_match(self::MOBILE, $phone) !== 1) {
            return null;
        }

        return 'https://wa.me/880'.substr($phone, 1).'?text='.rawurlencode($text);
    }
}
```

- [ ] **Step 4: Run unit test, expect PASS.**

- [ ] **Step 5: Lang strings** (new top-level group `whatsapp`, placed right before `'pin' => [` in both files)

`lang/en/app.php`:

```php
    'whatsapp' => [
        'send' => 'Send on WhatsApp',
        'remind' => 'Remind on WhatsApp',
        'opened' => 'WhatsApp opened :time · :name',
        'reminder' => "Assalamu alaikum :name,\nthe installment of :date on account :account still has :amount due.\n— :app",
        'receipt' => ":app receipt :receipt\n:date · :account\nReceived: :amount\n:remaining_label: :remaining",
        'remaining' => [
            'loan' => 'Loan remaining now',
            'saving' => 'Saving balance now',
            'fdr' => 'FDR held now',
        ],
        'disbursed' => ":app: loan :account given on :date: :amount.\nContract amount to repay: :contract.:installment",
        'installment_line' => "\nInstallment: :amount (:frequency).",
        'withdrawn' => ":app: :amount withdrawn from saving :account on :date.\nBalance now: :balance.",
    ],
```

`lang/bn/app.php`:

```php
    'whatsapp' => [
        'send' => 'হোয়াটসঅ্যাপে পাঠান',
        'remind' => 'হোয়াটসঅ্যাপে মনে করিয়ে দিন',
        'opened' => 'হোয়াটসঅ্যাপ খোলা হয়েছে :time · :name',
        'reminder' => "আসসালামু আলাইকুম :name,\n:date তারিখের কিস্তি :account হিসাবে :amount বাকি আছে।\n— :app",
        'receipt' => ":app রসিদ :receipt\n:date · :account\nজমা: :amount\n:remaining_label: :remaining",
        'remaining' => [
            'loan' => 'এখন ঋণ বাকি',
            'saving' => 'এখন সঞ্চয় স্থিতি',
            'fdr' => 'এখন এফডিআর জমা',
        ],
        'disbursed' => ":app: :date তারিখে :account ঋণ দেওয়া হয়েছে: :amount।\nপরিশোধযোগ্য চুক্তির টাকা: :contract।:installment",
        'installment_line' => "\nকিস্তি: :amount (:frequency)।",
        'withdrawn' => ":app: :date তারিখে সঞ্চয় :account থেকে :amount তোলা হয়েছে।\nএখন স্থিতি: :balance।",
    ],
```

- [ ] **Step 6: Enum, migration, model**

`app/Enums/WhatsAppKind.php`:

```php
<?php

namespace App\Enums;

/**
 * What a logged WhatsApp tap was for.
 */
enum WhatsAppKind: string
{
    case Reminder = 'reminder';
    case Receipt = 'receipt';
    case Notice = 'notice';
}
```

`database/migrations/2026_10_13_120000_create_whatsapp_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Taps on a WhatsApp button (the chat was opened; sending is not confirmed). Immutable, not a ledger row.
     */
    public function up(): void
    {
        Schema::create('whatsapp_logs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('collection_schedules')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('phone', 20);
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['schedule_id', 'created_at']);
            $table->index(['party_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_logs');
    }
};
```

`app/Models/WhatsAppLog.php`:

```php
<?php

namespace App\Models;

use App\Enums\WhatsAppKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A WhatsApp button tap: the chat was opened with the text filled in. Immutable.
 *
 * @property int $id
 * @property WhatsAppKind $kind
 * @property int $party_id
 * @property int|null $schedule_id
 * @property int|null $transaction_id
 * @property string $phone
 * @property int $sent_by
 * @property Carbon $created_at
 */
#[Fillable(['kind', 'party_id', 'schedule_id', 'transaction_id', 'phone', 'sent_by'])]
class WhatsAppLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'whatsapp_logs';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('WhatsApp logs are immutable.'));
        static::deleting(fn () => throw new LogicException('WhatsApp logs are immutable.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => WhatsAppKind::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
```

(No factory: tests create rows through `WhatsAppService::record`, the only writer.)

- [ ] **Step 7: Policies**

`app/Policies/CollectionSchedulePolicy.php`, add after `collect()`:

```php
    /**
     * Send the member a WhatsApp reminder for this row: its officer, or office staff who see schedules.
     */
    public function remind(User $user, CollectionSchedule $schedule): bool
    {
        return $this->collect($user, $schedule) || $this->viewAny($user);
    }
```

`app/Policies/TransactionPolicy.php`, add after `viewReceipt()` (import `App\Enums\TransactionType` and `App\Enums\Permission` if not imported):

```php
    /**
     * WhatsApp notice to the member about a loan given or a saving withdrawal: office staff who post entries.
     */
    public function shareNotice(User $user, Transaction $transaction): bool
    {
        return in_array($transaction->type, [TransactionType::LoanDisbursement, TransactionType::SavingWithdrawal], true)
            && $transaction->account !== null
            && $user->can(Permission::PostLedgerEntries->value)
            && $user->can('view', $transaction->account);
    }
```

- [ ] **Step 8: Service** — `app/Services/WhatsAppService.php`

```php
<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Enums\WhatsAppKind;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppLog;
use App\Support\Money;
use App\Support\Numerals;
use Illuminate\Support\Carbon;

/**
 * Message texts for the WhatsApp buttons and the log of taps. The only writer of `whatsapp_logs`.
 */
class WhatsAppService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function reminderText(CollectionSchedule $schedule, Money $remaining): string
    {
        return __('app.whatsapp.reminder', [
            'name' => $schedule->party->name,
            'date' => $this->date($schedule->due_date),
            'account' => $schedule->account->account_number,
            'amount' => $remaining->format(),
            'app' => config('app.name'),
        ]);
    }

    public function receiptText(Transaction $transaction): string
    {
        $account = $transaction->account;

        return __('app.whatsapp.receipt', [
            'app' => config('app.name'),
            'receipt' => (string) $transaction->receipt_no,
            'date' => $this->date($transaction->transaction_date),
            'account' => (string) $account?->account_number,
            'amount' => $transaction->amount->format(),
            'remaining_label' => $account ? __('app.whatsapp.remaining.'.$account->account_type->value) : '',
            'remaining' => $account ? $this->standing($account)->format() : '',
        ]);
    }

    /**
     * Text for a loan given or a saving withdrawal; null for any other entry.
     */
    public function noticeText(Transaction $transaction, Account $account): ?string
    {
        return match ($transaction->type) {
            TransactionType::LoanDisbursement => __('app.whatsapp.disbursed', [
                'app' => config('app.name'),
                'account' => $account->account_number,
                'date' => $this->date($transaction->transaction_date),
                'amount' => $transaction->amount->format(),
                'contract' => ($account->contract_amount ?? Money::zero())->format(),
                'installment' => $account->collectionPlan
                    ? __('app.whatsapp.installment_line', [
                        'amount' => $account->collectionPlan->installment_amount->format(),
                        'frequency' => __('app.schedules.frequencies.'.$account->collectionPlan->frequency->value),
                    ])
                    : '',
            ]),
            TransactionType::SavingWithdrawal => __('app.whatsapp.withdrawn', [
                'app' => config('app.name'),
                'amount' => $transaction->amount->format(),
                'account' => $account->account_number,
                'date' => $this->date($transaction->transaction_date),
                'balance' => $this->ledger->savingBalance($account)->format(),
            ]),
            default => null,
        };
    }

    public function record(WhatsAppKind $kind, Party $party, ?CollectionSchedule $schedule, ?Transaction $transaction, User $actor): WhatsAppLog
    {
        return WhatsAppLog::query()->create([
            'kind' => $kind,
            'party_id' => $party->id,
            'schedule_id' => $schedule?->id,
            'transaction_id' => $transaction?->id,
            'phone' => $party->phone,
            'sent_by' => $actor->id,
        ]);
    }

    public function label(WhatsAppLog $log): string
    {
        return __('app.whatsapp.opened', [
            'time' => Numerals::localize($log->created_at->copy()->setTimezone(config('app.display_timezone'))->format('H:i')),
            'name' => $log->sentBy->name,
        ]);
    }

    /**
     * Latest reminder label per schedule row for one Dhaka day (one query).
     *
     * @param  list<int>  $scheduleIds
     * @return array<int, string>
     */
    public function reminderLabels(array $scheduleIds, Carbon $day): array
    {
        if ($scheduleIds === []) {
            return [];
        }
        $start = Carbon::parse($day->toDateString(), config('app.display_timezone'))->utc();

        return WhatsAppLog::query()
            ->with('sentBy:id,name')
            ->where('kind', WhatsAppKind::Reminder)
            ->whereIn('schedule_id', $scheduleIds)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->copy()->addDay())
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (WhatsAppLog $log): array => [(int) $log->schedule_id => $this->label($log)])
            ->all();
    }

    private function standing(Account $account): Money
    {
        return match ($account->account_type) {
            AccountType::Loan => $this->ledger->loanOutstanding($account),
            AccountType::Saving => $this->ledger->savingBalance($account),
            AccountType::Fdr => $this->ledger->fdrHeld($account),
        };
    }

    private function date(Carbon $date): string
    {
        return Numerals::localize($date->format('d/m/Y'));
    }
}
```

(`orderBy('id')` + `mapWithKeys` keeps the latest per row. `created_at` is compared as a UTC timestamp; if the tests show SQLite string-compare problems, compare with `->toDateTimeString()` values.)

- [ ] **Step 9: Request, controller, routes**

`app/Http/Requests/LogWhatsAppRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\WhatsAppKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A WhatsApp button tap. The route's role middleware gates the area; the row itself is authorized in the controller.
 */
class LogWhatsAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(WhatsAppKind::class)],
            'schedule_id' => ['required_if:kind,reminder', 'nullable', 'integer', 'exists:collection_schedules,id'],
            'transaction_id' => ['required_unless:kind,reminder', 'nullable', 'integer', 'exists:transactions,id'],
        ];
    }

    public function kind(): WhatsAppKind
    {
        return WhatsAppKind::from($this->string('kind')->value());
    }
}
```

`app/Http/Controllers/WhatsAppLogController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\WhatsAppKind;
use App\Http\Requests\LogWhatsAppRequest;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Records that a WhatsApp button was tapped (admin and field areas) and returns the label to show.
 */
class WhatsAppLogController extends Controller
{
    public function __invoke(LogWhatsAppRequest $request, WhatsAppService $whatsapp): JsonResponse
    {
        $kind = $request->kind();
        $schedule = null;
        $transaction = null;

        if ($kind === WhatsAppKind::Reminder) {
            $schedule = CollectionSchedule::query()->with('party')->findOrFail($request->integer('schedule_id'));
            Gate::authorize('remind', $schedule);
            $party = $schedule->party;
        } else {
            $transaction = Transaction::query()->with(['party', 'account'])->findOrFail($request->integer('transaction_id'));
            Gate::authorize($kind === WhatsAppKind::Receipt ? 'viewReceipt' : 'shareNotice', $transaction);
            $party = $transaction->party;
        }
        abort_if($party === null, 422);

        $log = $whatsapp->record($kind, $party, $schedule, $transaction, $this->actor());

        return response()->json(['label' => $whatsapp->label($log)]);
    }
}
```

`routes/web.php`: import `App\Http\Controllers\WhatsAppLogController`; inside the `/admin` group (next to `transactions/{transaction}/receipt`):

```php
        Route::post('whatsapp/log', WhatsAppLogController::class)->middleware('throttle:60,1')->name('whatsapp.log');
```

and inside the `/field` group (next to `receipts/{transaction}`):

```php
        Route::post('whatsapp/log', WhatsAppLogController::class)->middleware('throttle:60,1')->name('whatsapp.log');
```

`tests/Feature/PermissionMatrixTest.php` `MATRIX`: add

```php
        'admin.whatsapp.log' => [self::MANAGER, self::ACCOUNTANT],
        'field.whatsapp.log' => [self::OFFICER],
```

`tests/Feature/DomainInvariantsTest.php`: if it holds a list of immutable tables guarded against query-builder writes, add `'whatsapp_logs'`; if it lists money columns, nothing to add (no money column).

- [ ] **Step 10: Feature tests** — `tests/Feature/WhatsAppShareTest.php`

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppLog;
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
        $service->record(\App\Enums\WhatsAppKind::Reminder, $schedule->party, $schedule, null, $this->officer);
        Carbon::setTestNow('2026-10-11 18:01:00'); // 00:01 Dhaka on the 12th
        $service->record(\App\Enums\WhatsAppKind::Reminder, $schedule->party, $schedule, null, $this->officer);

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

    private function schedule(User $officer): CollectionSchedule
    {
        return CollectionSchedule::factory()->create([
            'account_id' => Account::factory()->loan('1000')->create()->id,
            'field_officer_id' => $officer->id,
            'due_date' => '2026-10-12',
            'expected_amount' => Money::fromTaka('500'),
        ]);
    }
}
```

(Replace the inline FQCN `\App\Enums\WhatsAppKind` with a `use` import. If the app test locale is not `bn`, adjust the expected digits — check `config('app.locale')` in tests; the existing DateBoundaryTest shows which digits labels use.)

- [ ] **Step 11: Run, expect FAIL first (before Steps 6-9 if done TDD-first), then PASS**

Run: `php artisan test --compact tests/Unit/Support/WhatsAppTest.php tests/Feature/WhatsAppShareTest.php tests/Feature/PermissionMatrixTest.php tests/Feature/LangFilesTest.php tests/Feature/DomainInvariantsTest.php`
Expected: all PASS.

---

### Task 2: Buttons, JS and badges on day list, dashboard, missed/partial report, receipt page

**Files:**
- Create: `resources/views/components/whatsapp/button.blade.php`, `resources/views/components/whatsapp/remind.blade.php`, `resources/views/components/whatsapp/receipt.blade.php`, `resources/js/whatsapp.js`
- Modify: `resources/js/admin.js`, `resources/js/field.js`, `resources/views/field/_schedule_card.blade.php`, `resources/views/field/offline.blade.php` (row template), `app/Http/Controllers/Field/CollectionController.php` (labels + snapshot), `resources/views/admin/dashboard/_members.blade.php`, `app/Http/Controllers/Admin/DashboardController.php`, `resources/views/admin/reports/missed-partial.blade.php`, `app/Http/Controllers/Admin/ReportController.php` (missedPartial), `resources/views/receipts/show.blade.php`
- Test: `tests/Feature/WhatsAppShareTest.php` (append)

**Interfaces:**
- Consumes (Task 1): `WhatsApp::link`, `WhatsAppService::reminderText/receiptText/reminderLabels`, policies `remind`, `viewReceipt`, routes `admin.whatsapp.log` / `field.whatsapp.log`, lang `app.whatsapp.*`.
- Produces: components `<x-whatsapp.button :href :log :label :badge class>`, `<x-whatsapp.remind :schedule :remaining :badge class>`, `<x-whatsapp.receipt :transaction class>`; view variable `$reminded` (`array<int,string>` schedule id => label) on field home, dashboard (+ live fragment) and missed/partial report; snapshot row key `reminded_label` (string|null).

- [ ] **Step 1: Failing tests** (append to `WhatsAppShareTest`)

```php
    public function test_day_list_shows_remind_button_and_todays_label(): void
    {
        $schedule = $this->schedule($this->officer);
        app(WhatsAppService::class)->record(\App\Enums\WhatsAppKind::Reminder, $schedule->party, $schedule, null, $this->officer);

        $this->actingAs($this->officer)->get(route('field.home'))->assertOk()
            ->assertSee('https://wa.me/880'.substr($schedule->party->phone, 1), false)
            ->assertSee(route('field.whatsapp.log'), false)
            ->assertSee(__('app.whatsapp.opened', ['time' => '১০:০৫', 'name' => 'Rina']))
            ->assertSee('"reminded_label":', false);
    }

    public function test_no_button_when_the_member_has_no_mobile_number(): void
    {
        $schedule = $this->schedule($this->officer);
        $schedule->party->forceFill(['phone' => '0211223344'])->save();

        $this->actingAs($this->officer)->get(route('field.home'))->assertOk()->assertDontSee('https://wa.me/', false);
    }

    public function test_dashboard_and_missed_report_offer_reminders_to_office_staff(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $schedule = $this->schedule($this->officer);

        $this->actingAs($manager)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('https://wa.me/880'.substr($schedule->party->phone, 1), false)
            ->assertSee(route('admin.whatsapp.log'), false);

        $schedule->forceFill(['due_date' => '2026-10-10', 'status' => \App\Enums\ScheduleStatus::Missed])->save();
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
```

(Import `App\Enums\WhatsAppKind` / `ScheduleStatus` at the top instead of FQCNs. If `forceFill` on a schedule status trips a model guard, create the schedule with those attributes instead.)

Run: `php artisan test --compact tests/Feature/WhatsAppShareTest.php` -> new tests FAIL.

- [ ] **Step 2: Components**

`resources/views/components/whatsapp/button.blade.php`:

```blade
{{-- WhatsApp click-to-chat link; resources/js/whatsapp.js logs the tap (best effort) and fills the badge. --}}
@props(['href', 'log', 'label', 'badge' => null])
@php($logUrl = request()->routeIs('field.*') ? route('field.whatsapp.log') : route('admin.whatsapp.log'))
<span class="js-whatsapp-wrap d-inline-block">
    <a href="{{ $href }}" target="_blank" rel="noopener" {{ $attributes->merge(['class' => 'btn btn-outline-success js-whatsapp']) }}
       data-log-url="{{ $logUrl }}" data-csrf="{{ csrf_token() }}" data-log="{{ json_encode($log) }}">
        <i class="bi bi-whatsapp" aria-hidden="true"></i> {{ $label }}
    </a>
    <span @class(['small text-success d-block', 'd-none' => $badge === null]) data-whatsapp-badge>{{ $badge }}</span>
</span>
```

`resources/views/components/whatsapp/remind.blade.php`:

```blade
{{-- Reminder for one schedule row. Needs the row's party + account loaded. --}}
@props(['schedule', 'remaining', 'badge' => null])
@inject('whatsapp', 'App\Services\WhatsAppService')
@php($href = $remaining->isPositive() ? \App\Support\WhatsApp::link($schedule->party->phone, $whatsapp->reminderText($schedule, $remaining)) : null)
@if ($href)
    @can('remind', $schedule)
        <x-whatsapp.button :href="$href" :log="['kind' => 'reminder', 'schedule_id' => $schedule->id]" :label="__('app.whatsapp.remind')" :badge="$badge" {{ $attributes }} />
    @endcan
@endif
```

`resources/views/components/whatsapp/receipt.blade.php`:

```blade
{{-- Receipt to the member. Needs the transaction's party + account loaded. --}}
@props(['transaction'])
@inject('whatsapp', 'App\Services\WhatsAppService')
@php($href = $transaction->receipt_no ? \App\Support\WhatsApp::link($transaction->party?->phone, $whatsapp->receiptText($transaction)) : null)
@if ($href)
    @can('viewReceipt', $transaction)
        <x-whatsapp.button :href="$href" :log="['kind' => 'receipt', 'transaction_id' => $transaction->id]" :label="__('app.whatsapp.send')" {{ $attributes }} />
    @endcan
@endif
```

(If passing `{{ $attributes }}` to a nested component does not merge as expected in this Laravel version, pass `class` explicitly: `:class="$attributes->get('class')"`. Check with `search-docs` "anonymous components attributes".)

- [ ] **Step 3: JS** — `resources/js/whatsapp.js`

```js
/**
 * WhatsApp buttons (resources/views/components/whatsapp/button.blade.php): the link opens WhatsApp as usual;
 * the tap is also logged (best effort, never blocks the link) and the returned label is shown next to it.
 */
export function initWhatsAppButtons() {
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a.js-whatsapp') : null;
        if (!link) {
            return;
        }
        fetch(link.dataset.logUrl, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': link.dataset.csrf },
            body: link.dataset.log,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                const badge = link.closest('.js-whatsapp-wrap')?.querySelector('[data-whatsapp-badge]');
                if (data?.label && badge) {
                    badge.textContent = data.label;
                    badge.classList.remove('d-none');
                }
            })
            .catch(() => {});
    });
}
```

`resources/js/admin.js`: add `import { initWhatsAppButtons } from './whatsapp.js';` with the other imports and call `initWhatsAppButtons();` at module level (once; document-level listener also covers the dashboard's refreshed fragment).
`resources/js/field.js`: same import (`./whatsapp.js`) and call `initWhatsAppButtons();` right after the PIN lock block.

- [ ] **Step 4: Field day list + snapshot**

`app/Http/Controllers/Field/CollectionController.php` `index()`: inject `WhatsAppService $whatsapp` into the method signature (`public function index(WhatsAppService $whatsapp): View`), compute after `$rows` and `$remaining` exist:

```php
        $reminded = $whatsapp->reminderLabels($rows->modelKeys(), Carbon::parse($today->toDateString()));
```

add to each snapshot row array: `'reminded_label' => $reminded[$schedule->id] ?? null,` and to the view data: `'reminded' => $reminded,`. (Use the same variable that holds every listed row; if `$rows` is not a list, use `->values()->modelKeys()`.)

Pass `$reminded` down to `_schedule_card` (check `field/home.blade.php` and `field/_client_card.blade.php` includes; Blade `@include` inherits parent variables, so usually nothing to change). In `resources/views/field/_schedule_card.blade.php`, after the Collect link block:

```blade
    @if ($schedule->status->isCollectable())
        <x-whatsapp.remind :schedule="$schedule" :remaining="$remaining[$schedule->id]" :badge="$reminded[$schedule->id] ?? null" class="btn-lg w-100 mt-2" />
    @endif
```

(`.js-whatsapp-wrap` is `d-inline-block`; for the full-width field button change the wrap to `d-block` when the class contains `w-100`, or simply make the wrapper `d-block` in the field: simplest is `<span class="js-whatsapp-wrap d-block">` always — check the admin tables still look fine, they render in a cell anyway.)

`resources/views/field/offline.blade.php`, inside the `<template id="offline-row">` card body, add after the amounts grid:

```blade
<div class="small text-success" data-field="reminded_label"></div>
```

(`fill()` sets textContent from the snapshot row; null renders as empty.)

- [ ] **Step 5: Dashboard**

`app/Http/Controllers/Admin/DashboardController.php` `data()`: inject `WhatsAppService` (add a parameter to `data()` and pass from both actions) and add:

```php
        $figures = $reports->dashboard($day);
        $ids = collect($figures['unpaid']['rows'])
            ->merge(collect($figures['officers'])->flatMap(fn (array $officer): array => $officer['members']))
            ->map(fn (array $line): int => $line['schedule']->id)->unique()->values()->all();

        return [
            'day' => $day,
            'figures' => $figures,
            'activity' => $reports->activity($day),
            'reminded' => $whatsapp->reminderLabels($ids, $day),
        ];
```

(update the `@return` array shape to include `reminded: array<int, string>`.)

`resources/views/admin/dashboard/_members.blade.php`: in each member row, next to the phone link (line ~34), add:

```blade
<x-whatsapp.remind :schedule="$line['schedule']" :remaining="$line['remaining']" :badge="$reminded[$line['schedule']->id] ?? null" class="btn-sm" />
```

(Use the loop variable names of that partial; it receives `$lines` with `schedule`, `collected`, `remaining`.)

- [ ] **Step 6: Missed/partial report**

`ReportController::missedPartial()`: inject `WhatsAppService $whatsapp`, build the report into `$report`, pass `'reminded' => $whatsapp->reminderLabels(array_map(fn (array $line): int => $line['schedule']->id, $report['rows']), Carbon::parse(today(config('app.display_timezone'))->toDateString()))`.
`resources/views/admin/reports/missed-partial.blade.php`: under the phone (line ~68) add the same `<x-whatsapp.remind ... class="btn-sm mt-1" />` with `$line['schedule']`, `$line['remaining']`, `$reminded[...] ?? null`. Keep the table rectangular (no new column if `.js-report-table` export would break; putting it inside the existing cell is fine — exported text then includes the button label; if that is ugly in Excel, wrap the component in `<span class="d-print-none">` and check report-export.js excludes it, otherwise accept).

- [ ] **Step 7: Receipt page**

`resources/views/receipts/show.blade.php`, first item inside `<div class="d-grid gap-2 mt-3 d-print-none">`:

```blade
        <x-whatsapp.receipt :transaction="$transaction" class="btn-lg w-100" />
```

- [ ] **Step 8: Run tests + build**

Run: `php artisan test --compact tests/Feature/WhatsAppShareTest.php tests/Feature/QueryCountTest.php tests/Feature/FieldCollectionScreensTest.php tests/Feature/DashboardTest.php tests/Feature/FieldPwaTest.php` then `npm run build`.
Expected: PASS; QueryCountTest unchanged (labels are one query per page; components use loaded relations — if QueryCountTest fails, the cause is a lazy relation in a component: eager load it in the page's query, do not relax the test).

---

### Task 3: Disbursement / withdrawal notices on the account page

**Files:**
- Create: `resources/views/components/whatsapp/notice.blade.php`
- Modify: `app/Http/Controllers/Admin/TransactionController.php`, `app/Http/Controllers/Admin/AccountController.php` (store + show), `resources/views/admin/accounts/show.blade.php`
- Test: `tests/Feature/WhatsAppShareTest.php` (append)

**Interfaces:**
- Consumes: `WhatsAppService::noticeText`, policy `shareNotice`, `<x-whatsapp.button>`.
- Produces: session flash key `whatsapp_notice` (transaction id); view variable `$notice` (?Transaction) on account show.

- [ ] **Step 1: Failing tests** (append)

```php
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
            ->assertSee(rawurlencode(Money::fromTaka('700')->format()), false); // balance now
    }

    public function test_recent_entries_offer_notices_only_for_loans_given_and_withdrawals(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $loan = Account::factory()->loan('1200')->create();
        Transaction::factory()->of($loan, TransactionType::LoanDisbursement, '1000')->create();
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '100')->create(['receipt_no' => 'R-202610-00003']);

        $html = (string) $this->actingAs($accountant)->get(route('admin.accounts.show', $loan))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '"kind":"notice"'));
        $this->assertStringContainsString(rawurlencode(Money::fromTaka('1200')->format()), $html); // contract amount
    }
```

(`data-log` is HTML-escaped JSON; if `substr_count` on `"kind":"notice"` misses because of `&quot;`, count `&quot;kind&quot;:&quot;notice&quot;` instead.)

Run -> FAIL.

- [ ] **Step 2: Component** — `resources/views/components/whatsapp/notice.blade.php`

```blade
{{-- Notice to the member about a loan given or a saving withdrawal. $account/$party passed in (no lazy loading in lists). --}}
@props(['transaction', 'account'])
@inject('whatsapp', 'App\Services\WhatsAppService')
@php($text = $whatsapp->noticeText($transaction, $account))
@php($href = $text ? \App\Support\WhatsApp::link($account->party->phone, $text) : null)
@if ($href)
    @can('shareNotice', $transaction)
        <x-whatsapp.button :href="$href" :log="['kind' => 'notice', 'transaction_id' => $transaction->id]" :label="__('app.whatsapp.send')" {{ $attributes }} />
    @endcan
@endif
```

- [ ] **Step 3: Flash after posting**

`TransactionController::store()`: chain on the redirect:

```php
        $redirect = redirect()->route('admin.accounts.show', $account)->with('status', $status);

        return in_array($transaction->type, [TransactionType::LoanDisbursement, TransactionType::SavingWithdrawal], true)
            ? $redirect->with('whatsapp_notice', $transaction->id)
            : $redirect;
```

(import `App\Enums\TransactionType`.)

`AccountController::store()`: after `$account` is created, for a loan opened with a cash disbursement:

```php
        $notice = Transaction::query()->where('account_id', $account->id)->where('type', TransactionType::LoanDisbursement)->value('id');

        return redirect()->route('admin.accounts.show', $account)
            ->with('status', __('app.accounts.opened'))
            ->with('whatsapp_notice', $notice);
```

(`value()` is null for savings/FDR and carried-in loans, which use `loan_opening_disbursement`; a null flash shows nothing.)

`AccountController::show()`: add to the view data:

```php
            'notice' => session('whatsapp_notice')
                ? Transaction::query()->where('account_id', $account->id)->find(session('whatsapp_notice'))?->setRelation('account', $account)
                : null,
```

- [ ] **Step 4: Account page markup**

`resources/views/admin/accounts/show.blade.php`, inside the `session('status')` alert (line ~34), after the status text:

```blade
            @if ($notice)
                <x-whatsapp.notice :transaction="$notice" :account="$account" class="ms-auto" />
            @endif
```

In the recent-entries loop, in the second `d-flex` row (before the running-balance span):

```blade
                                    @unless ($line->reversed || $isReversal)
                                        <x-whatsapp.notice :transaction="$transaction" :account="$account" class="btn-sm" />
                                    @endunless
```

(`$account->party` is loaded by `show()`; statement lines carry the `account` relation for the policy.)

- [ ] **Step 5: Run** — `php artisan test --compact tests/Feature/WhatsAppShareTest.php tests/Feature/AccountManagementTest.php tests/Feature/LoanSetupTest.php` -> PASS.

---

### Task 4: Browser check, docs, full gate

**Files:**
- Modify: `docs/ROADMAP.md` (tick "Manual WhatsApp share", handoff line), `docs/ARCHITECTURE.md` (add `whatsapp_logs` to Tables)

- [ ] **Step 1: Browser check** (Playwright MCP, 390px for field, 1440px for admin; scratch SQLite on localhost as in the PIN plan: `DB_CONNECTION=sqlite DB_DATABASE=<scratch>/wa.sqlite php artisan migrate:fresh --seed --force`, an officer with a party + schedule due today, `npm run build`, `php artisan serve --port=8123` in background). Intercept the wa.me navigation (`context.route('https://wa.me/**', r => r.abort())` or check `href`) — do not open real WhatsApp. Check: field Remind -> badge appears, row in `whatsapp_logs`; reload -> badge persists; receipt page button; dashboard member row button + badge survives the 60 s refresh (refresh re-renders from server); account page: post a withdrawal -> flash with button; text decoded from href reads correctly in Bengali.

- [ ] **Step 2: Docs**

`docs/ARCHITECTURE.md` Tables list, add:
`- \`whatsapp_logs\`: kind (reminder/receipt/notice), party_id, schedule_id null, transaction_id null, phone, sent_by, created_at (immutable; taps on wa.me buttons, not ledger rows)`

`docs/ROADMAP.md`: tick `- [ ] Manual WhatsApp share` and append handoff:
`- 2026-10-13: Manual WhatsApp share: \`App\\Support\\WhatsApp::link\`, \`WhatsAppService\` (texts from \`app.whatsapp\`, \`record\`, \`reminderLabels\`), immutable \`whatsapp_logs\` (run \`php artisan migrate\`), POST \`admin.whatsapp.log\` / \`field.whatsapp.log\` (best effort from \`resources/js/whatsapp.js\`), components \`x-whatsapp.remind|receipt|notice\` on field day list (+ offline snapshot label), dashboard members, missed/partial report, receipt page, account page (flash after disbursement/withdrawal + recent entries). Receipt text shows the account figure now, not as of the receipt. Next: member portal.`

- [ ] **Step 3: Full gate** — `php artisan test --compact && vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --no-progress && npm run build` -> all pass.
