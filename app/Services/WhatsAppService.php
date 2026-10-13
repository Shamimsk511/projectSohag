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

        $text = __('app.whatsapp.receipt', [
            'app' => config('app.name'),
            'name' => (string) $transaction->party?->name,
            'receipt' => (string) $transaction->receipt_no,
            'date' => $this->date($transaction->transaction_date),
            'account' => (string) $account?->account_number,
            'amount' => $transaction->amount->format(),
            'remaining_label' => $account ? __('app.whatsapp.remaining.'.$account->account_type->value) : '',
            'remaining' => $account ? $this->standing($account)->format() : '',
        ]);
        $access = $transaction->party?->portalAccess;

        return $access ? $text."\n".__('app.whatsapp.portal_line', ['link' => route('portal.home')]) : $text;
    }

    public function portalText(Party $party, string $link): string
    {
        return __('app.whatsapp.portal', ['name' => $party->name, 'link' => $link, 'app' => config('app.name')]);
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
        $log = WhatsAppLog::query()->create([
            'kind' => $kind,
            'party_id' => $party->id,
            'schedule_id' => $schedule?->id,
            'transaction_id' => $transaction?->id,
            'phone' => $party->phone,
            'sent_by' => $actor->id,
        ]);
        $log->setRelation('sentBy', $actor);

        return $log;
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
     * @param  Carbon  $day  A Dhaka calendar date (only its date part is used)
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
