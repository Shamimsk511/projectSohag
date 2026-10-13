@php
    $receiptRoute ??= 'admin.transactions.receipt';
    $showNotes ??= true;
    $date = fn (?\Illuminate\Support\Carbon $value): ?string => $value ? \App\Support\Numerals::localize($value->format('d/m/Y')) : null;
    $type = $account->account_type->value;
    $reversible = fn (\App\Support\StatementLine $line): bool => $actions && $account->isActive() && ! $line->reversed
        && $line->transaction->type !== \App\Enums\TransactionType::Reversal
        && auth()->user()->can('reverse', $line->transaction);
    $showActions = collect($statement->lines)->contains($reversible);
@endphp

<div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.accounts.statement_date') }}</th>
                        <th>{{ __('app.accounts.statement_particulars') }}</th>
                        <th>{{ __('app.accounts.statement_receipt') }}</th>
                        <th class="text-end">{{ __('app.accounts.statement_in.'.$type) }}</th>
                        <th class="text-end">{{ __('app.accounts.statement_out.'.$type) }}</th>
                        <th class="text-end">{{ __('app.accounts.statement_running.'.$type) }}</th>
                        @if ($showActions)
                            <th><span class="visually-hidden">{{ __('app.transactions.reverse') }}</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    <tr class="fw-semibold">
                        <td>{{ $date($statement->from) }}</td>
                        <td colspan="4">{{ __('app.accounts.statement_opening') }}</td>
                        <td class="text-end">{{ $statement->opening->format() }}</td>
                        @if ($showActions)<td></td>@endif
                    </tr>
                    @forelse ($statement->lines as $line)
                        @php($transaction = $line->transaction)
                        <tr>
                            <td class="text-nowrap">{{ $date($transaction->transaction_date) }}</td>
                            <td>
                                {{ __('app.transactions.types.'.$transaction->type->value) }}
                                @if ($line->reversed)
                                    <span class="badge text-bg-warning">{{ __('app.accounts.statement_reversed') }}</span>
                                @endif
                                @if ($transaction->reversedTransaction)
                                    <div class="small text-body-secondary">
                                        {{ __('app.accounts.statement_reversal_of', ['ref' => $transaction->reversedTransaction->receipt_no ?? $date($transaction->reversedTransaction->transaction_date)]) }}
                                    </div>
                                @endif
                                @if ($showNotes && $transaction->description)
                                    <div class="small text-body-secondary">{{ $transaction->description }}</div>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                @if ($transaction->receipt_no)
                                    <a href="{{ route($receiptRoute, $transaction) }}" @if ($receiptRoute !== 'portal.receipts.show') target="_blank" rel="noopener" @endif>{{ $transaction->receipt_no }}</a>
                                @else
                                    {{ $transaction->reference }}
                                @endif
                            </td>
                            <td class="text-end text-nowrap">{{ $line->in?->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line->out?->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line->running->format() }}</td>
                            @if ($showActions)
                                <td class="text-end">
                                    @if ($reversible($line))
                                        <form method="POST" action="{{ route('admin.transactions.reverse', $transaction) }}" class="js-confirm"
                                              data-confirm="{{ json_encode([
                                                  'title' => __('app.transactions.reverse_title'),
                                                  'text' => __('app.transactions.reverse_text'),
                                                  'input' => __('app.transactions.reverse_reason'),
                                                  'confirm' => __('app.transactions.reverse_yes'),
                                                  'cancel' => __('app.cancel'),
                                              ]) }}">
                                            @csrf
                                            <input type="hidden" name="reason">
                                            <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">
                                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> {{ __('app.transactions.reverse') }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $showActions ? 7 : 6 }}" class="text-body-secondary">{{ __('app.accounts.statement_empty') }}</td>
                        </tr>
                    @endforelse
                    <tr class="fw-semibold">
                        <td>{{ $date($statement->to) }}</td>
                        <td colspan="4">{{ __('app.accounts.statement_closing') }}</td>
                        <td class="text-end">{{ $statement->closing->format() }}</td>
                        @if ($showActions)<td></td>@endif
                    </tr>
                </tbody>
            </table>
        </div>
    </div>


@if ($showActions)
    @push('js')
        @vite('resources/js/admin/accounts.js')
    @endpush
@endif
