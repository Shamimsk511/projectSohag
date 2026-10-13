@php
    $date = fn (\Illuminate\Support\Carbon $value): string => \App\Support\Numerals::localize($value->format('d/m/Y'));
    $receivedBy = $transaction->fieldOfficer ?? $transaction->createdBy;
@endphp
<div class="card">
    <div class="card-body">
        <div class="text-center mb-3">
            <div class="h5 mb-0">{{ __('app.site.org_name') }}</div>
            <div class="text-body-secondary">{{ __('app.receipts.title') }}</div>
        </div>
        <dl class="row mb-0">
            <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.receipt_no') }}</dt>
            <dd class="col-7 fw-semibold">{{ $transaction->receipt_no }}</dd>
            <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.date') }}</dt>
            <dd class="col-7">{{ $date($transaction->transaction_date) }}</dd>
            <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.member') }}</dt>
            <dd class="col-7">{{ $transaction->party?->name }} ({{ $transaction->party?->party_code }})</dd>
            <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.account') }}</dt>
            <dd class="col-7">{{ $transaction->account?->account_number }}</dd>
            <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.for') }}</dt>
            <dd class="col-7">{{ __('app.transactions.types.'.$transaction->type->value) }}</dd>
            <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.amount') }}</dt>
            <dd class="col-7 fs-4 fw-bold">{{ $transaction->amount->format() }}</dd>
            @if ($transaction->payment_method)
                <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.payment_method') }}</dt>
                <dd class="col-7">{{ __('app.transactions.payment_methods.'.$transaction->payment_method->value) }}</dd>
            @endif
            @if ($transaction->reference)
                <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.reference') }}</dt>
                <dd class="col-7">{{ $transaction->reference }}</dd>
            @endif
            <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.received_by') }}</dt>
            <dd class="col-7 mb-0">{{ $receivedBy?->name }}</dd>
        </dl>
    </div>
</div>
