<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\PlanFrequency;
use App\Models\Account;
use App\Models\CollectionPlan;
use App\Models\Party;
use App\Models\User;
use App\Rules\TakaAmount;
use App\Support\CollectionPlanData;
use App\Support\Installments;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Account::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'principal_amount' => ['required_if:account_type,'.AccountType::Loan->value, 'nullable', new TakaAmount],
            'extra_amount' => ['nullable', new TakaAmount],
            'contract_amount' => ['nullable', new TakaAmount],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'existing_loan' => ['nullable', 'boolean'],
            'received_amount' => ['nullable', new TakaAmount],
            'carried_as_of' => ['required_if:existing_loan,1', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.today(config('app.display_timezone'))->toDateString()],
            'opened_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today(config('app.display_timezone'))->toDateString()],
            ...$this->collectionRules(),
        ];
    }

    /**
     * Loan collection section: officer and plan. All optional; no installment means no plan yet.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function collectionRules(): array
    {
        return [
            'field_officer_id' => ['nullable', 'integer', Rule::in(User::activeFieldOfficers()->pluck('id')->all())],
            'frequency' => ['nullable', Rule::enum(PlanFrequency::class)],
            'installment_amount' => ['nullable', new TakaAmount],
            'installment_count' => ['nullable', 'integer', 'between:1,1000'],
            'installment_basis' => ['nullable', Rule::in(['amount', 'count'])],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'starts_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.today(config('app.display_timezone'))->toDateString()],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->accountType() !== AccountType::Loan) {
                    return;
                }

                if (($this->filled('installment_amount') || $this->filled('installment_count')) && ! $this->amountToCollect()->isPositive()) {
                    $validator->errors()->add('installment_amount', __('app.accounts.nothing_to_collect'));
                }

                /** @var Party $party */
                $party = $this->route('party');
                $officerId = $this->integer('field_officer_id');
                if ($officerId !== 0 && $party->activeAssignment()->value('field_officer_id') !== $officerId && ! $this->user()?->can('assign', $party)) {
                    $validator->errors()->add('field_officer_id', __('app.accounts.officer_change_forbidden'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'account_type' => __('app.accounts.type'),
            'principal_amount' => __('app.accounts.principal_amount'),
            'extra_amount' => __('app.accounts.agreed_extra'),
            'contract_amount' => __('app.accounts.agreed_amount'),
            'payment_method' => __('app.transactions.payment_method'),
            'received_amount' => __('app.accounts.received_before'),
            'carried_as_of' => __('app.accounts.carried_as_of'),
            'opened_at' => __('app.accounts.opened_at'),
            'field_officer_id' => __('app.parties.field_officer'),
            'frequency' => __('app.schedules.frequency'),
            'installment_amount' => __('app.schedules.installment_amount'),
            'installment_count' => __('app.accounts.installment_count'),
            'weekdays' => __('app.schedules.weekdays'),
            'starts_on' => __('app.accounts.first_collection'),
        ];
    }

    public function accountType(): AccountType
    {
        return $this->enum('account_type', AccountType::class) ?? AccountType::Saving;
    }

    /**
     * Saving or FDR: the optional agreed amount (end-of-term amount or agreed payback).
     */
    public function agreedAmount(): ?Money
    {
        return $this->filled('contract_amount') ? Money::fromTaka($this->string('contract_amount')->value()) : null;
    }

    public function principalAmount(): Money
    {
        return Money::fromTaka($this->string('principal_amount')->value());
    }

    public function agreedExtra(): Money
    {
        return $this->filled('extra_amount') ? Money::fromTaka($this->string('extra_amount')->value()) : Money::zero();
    }

    /**
     * Admin only (checked by AccountService): the loan existed before go-live.
     */
    public function isExistingLoan(): bool
    {
        return $this->accountType() === AccountType::Loan && $this->boolean('existing_loan');
    }

    public function receivedBefore(): Money
    {
        return $this->filled('received_amount') ? Money::fromTaka($this->string('received_amount')->value()) : Money::zero();
    }

    public function carriedAsOf(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('carried_as_of')->value()) ?: today();
    }

    public function paymentMethod(): PaymentMethod
    {
        return $this->enum('payment_method', PaymentMethod::class) ?? PaymentMethod::Cash;
    }

    public function openedAt(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('opened_at')->value()) ?: today();
    }

    public function fieldOfficer(): ?User
    {
        return $this->filled('field_officer_id') ? User::findOrFail($this->integer('field_officer_id')) : null;
    }

    /**
     * What the plan has to collect: the contract, less what a carried-in loan already received.
     */
    public function amountToCollect(): Money
    {
        $contract = $this->principalAmount()->plus($this->agreedExtra());

        return $this->isExistingLoan() ? $contract->minus($this->receivedBefore()) : $contract;
    }

    /**
     * From the installment or the number of installments, whichever the office typed last
     * (`installment_basis`, set by the form script; without it the installment wins). Null = no plan.
     */
    public function installments(): ?Installments
    {
        $total = $this->amountToCollect();
        if (! $total->isPositive()) {
            return null;
        }

        if ($this->filled('installment_count') && ($this->input('installment_basis') === 'count' || ! $this->filled('installment_amount'))) {
            return Installments::ofCount($total, $this->integer('installment_count'));
        }

        $installment = $this->filled('installment_amount') ? Money::fromTaka($this->string('installment_amount')->value()) : null;

        return $installment?->isPositive() ? Installments::ofAmount($total, $installment) : null;
    }

    public function collectionPlan(): ?CollectionPlanData
    {
        $installments = $this->installments();
        if ($installments === null) {
            return null;
        }

        $frequency = $this->enum('frequency', PlanFrequency::class) ?? PlanFrequency::Daily;
        $weekdays = $frequency === PlanFrequency::Daily
            ? ($this->has('weekdays') ? array_values(array_map(intval(...), (array) $this->input('weekdays', []))) : CollectionPlan::DEFAULT_WEEKDAYS)
            : null;

        return new CollectionPlanData($frequency, $installments->installment, $weekdays, $this->firstCollectionOn());
    }

    /**
     * The entered date, else the day after the loan date (today at the earliest).
     */
    public function firstCollectionOn(): Carbon
    {
        if ($this->filled('starts_on')) {
            return Carbon::createFromFormat('!Y-m-d', $this->string('starts_on')->value()) ?: today();
        }

        $today = Carbon::parse(today(config('app.display_timezone'))->toDateString());
        $dayAfter = $this->filled('opened_at') ? Carbon::parse($this->string('opened_at')->value())->addDay() : $today->copy()->addDay();

        return $dayAfter->max($today);
    }
}
