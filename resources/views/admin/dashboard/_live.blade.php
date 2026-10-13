{{-- Refreshable dashboard body. Expects $day, $figures, $activity. Also served alone by admin.dashboard.live. --}}
@php
    use App\Support\Numerals;

    $money = fn ($amount) => $amount->format();
    $dayValue = $day->toDateString();
    $time = fn (?\Illuminate\Support\Carbon $value): ?string => $value
        ? Numerals::localize($value->copy()->timezone(config('app.display_timezone'))->format('h:i A'))
        : null;
@endphp
    <div class="row g-3 mb-3">
        @foreach ([
            'expected' => $figures['expected'],
            'collected' => $figures['collected'],
            'received_today' => $figures['received_today'],
        ] as $key => $amount)
            <div class="col-md-3">
                <div class="card h-100"><div class="card-body">
                    <div class="text-body-secondary small">{{ __('app.dash.'.$key) }}</div>
                    <div class="fs-3 fw-semibold">{{ $money($amount) }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        @foreach (\App\Enums\ScheduleStatus::cases() as $status)
            @continue($status === \App\Enums\ScheduleStatus::Cancelled)
            <span class="badge text-bg-light border fs-6 fw-normal">
                {{ __('app.schedules.statuses.'.$status->value) }}:
                {{ Numerals::localize((string) ($figures['counts'][$status->value] ?? 0)) }}
            </span>
        @endforeach
    </div>

    <h2 class="h5">{{ __('app.dash.standing') }}</h2>
    <div class="row g-3 mb-4">
        @foreach ([
            'loan_outstanding' => $figures['loan_outstanding'],
            'saving_balance' => $figures['saving_balance'],
            'fdr_held' => $figures['fdr_held'],
            'cash_in_hand' => $figures['cash_in_hand'],
        ] as $key => $amount)
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <div class="text-body-secondary small">{{ __('app.dash.'.$key) }}</div>
                    <div class="fs-4 fw-semibold">{{ $money($amount) }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <h2 class="h5">{{ __('app.dash.day_title') }}</h2>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body">
                <div class="text-body-secondary small">{{ __('app.dash.loans_issued') }}</div>
                <div class="fs-4 fw-semibold">{{ $money($figures['loans']['principal']) }}</div>
                <div class="small text-body-secondary">
                    {{ __('app.dash.loans_count', ['count' => Numerals::localize((string) $figures['loans']['count'])]) }}
                    · {{ __('app.accounts.agreed_extra') }}: {{ $money($figures['loans']['extra']) }}
                </div>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body">
                <div class="text-body-secondary small">{{ __('app.dash.office_day') }}</div>
                <div class="fs-4 fw-semibold">{{ $money($figures['office']['income']) }}</div>
                <div class="small text-body-secondary">
                    {{ __('app.office_entries.expense_total') }}: {{ $money($figures['office']['expense']) }}
                </div>
            </div></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title mb-0">{{ __('app.dash.officers') }}</h3></div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.dash.officer') }}</th>
                        <th class="text-end">{{ __('app.dash.rows') }}</th>
                        <th class="text-end">{{ __('app.dash.paid_rows') }}</th>
                        <th class="text-end">{{ __('app.dash.expected') }}</th>
                        <th class="text-end">{{ __('app.dash.collected') }}</th>
                        <th class="text-end">{{ __('app.dash.remaining') }}</th>
                        <th style="min-width: 8rem">{{ __('app.dash.rate') }}</th>
                        <th class="text-end">{{ __('app.dash.officer_cash') }}</th>
                        <th>{{ __('app.dash.last_collection') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($figures['officers'] as $line)
                        <tr>
                            <td>{{ $line['officer']->name }}</td>
                            <td class="text-end">{{ Numerals::localize((string) $line['rows']) }}</td>
                            <td class="text-end">{{ Numerals::localize((string) $line['paid']) }}</td>
                            <td class="text-end text-nowrap">{{ $money($line['expected']) }}</td>
                            <td class="text-end text-nowrap">{{ $money($line['collected']) }}</td>
                            <td class="text-end text-nowrap">{{ $money($line['expected']->minus($line['collected'])->isPositive() ? $line['expected']->minus($line['collected']) : \App\Support\Money::zero()) }}</td>
                            <td>
                                <div class="progress" role="progressbar" aria-valuenow="{{ $line['rate'] }}" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar" style="width: {{ min(100, $line['rate']) }}%">{{ Numerals::localize((string) $line['rate']) }}%</div>
                                </div>
                            </td>
                            <td class="text-end text-nowrap">{{ $money($line['cash']) }}</td>
                            <td class="text-nowrap">{{ $time($line['last_at']) ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-body-secondary py-4">{{ __('app.dash.no_rows') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center">
            <h3 class="card-title mb-0">{{ __('app.dash.activity') }}</h3>
            <span class="ms-auto small text-body-secondary" data-live-stamp>{{ __('app.dash.updated', ['time' => $time(now())]) }}</span>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.dash.time') }}</th>
                        <th>{{ __('app.dash.by') }}</th>
                        <th>{{ __('app.dash.member') }}</th>
                        <th>{{ __('app.transactions.type') }}</th>
                        <th class="text-end">{{ __('app.transactions.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activity as $entry)
                        @php
                            $isReversal = $entry->type === \App\Enums\TransactionType::Reversal;
                            $struck = (bool) $entry->reversal;
                        @endphp
                        <tr @class(['text-body-secondary' => $struck || $isReversal])>
                            <td class="text-nowrap">{{ $time($entry->created_at) }}</td>
                            <td>{{ ($entry->fieldOfficer ?? $entry->createdBy)?->name }}</td>
                            <td>
                                @if ($entry->party && $entry->account)
                                    <a href="{{ route('admin.accounts.show', $entry->account) }}">{{ $entry->party->name }}</a>
                                    <span class="small text-body-secondary">{{ $entry->account->account_number }}</span>
                                @else
                                    <span class="text-body-secondary">—</span>
                                @endif
                            </td>
                            <td>
                                {{ __('app.transactions.types.'.$entry->type->value) }}
                                @if ($entry->officeHead)
                                    <span class="badge text-bg-light border">{{ $entry->officeHead->name }}</span>
                                @endif
                                @if ($struck)
                                    <span class="badge text-bg-warning">{{ __('app.accounts.statement_reversed') }}</span>
                                @endif
                            </td>
                            <td @class(['text-end text-nowrap', 'text-decoration-line-through' => $struck])>{{ $money($entry->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-body-secondary py-4">{{ __('app.dash.no_activity') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
