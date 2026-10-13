{{-- Money position cards. Expects $position from ReportService::position(). Shown on its own page and on the dashboard. --}}
@php
    use App\Support\Numerals;

    $month = $position['month'];
    $sections = [
        [
            'title' => __('app.reports.position.cash'),
            'total' => $position['cash']['total'],
            'class' => 'border-primary',
            'lines' => [
                [__('app.reports.position.office_cash'), $position['cash']['office'], route('admin.reports.cash-reconciliation')],
                [__('app.reports.position.officers_cash'), $position['cash']['officers'], route('admin.cash-handovers.index')],
            ],
        ],
        [
            'title' => __('app.reports.position.owed_to_us'),
            'total' => $position['owed_to_us']['loans'],
            'note' => __('app.reports.position.loan_count', ['count' => Numerals::localize((string) $position['owed_to_us']['count'])]),
            'class' => 'border-success',
            'lines' => [
                [__('app.reports.position.loans'), $position['owed_to_us']['loans'], route('admin.reports.outstanding')],
                [__('app.reports.position.overdue'), $position['owed_to_us']['overdue'], route('admin.reports.outstanding', ['overdue' => 1])],
            ],
        ],
        [
            'title' => __('app.reports.position.we_owe'),
            'total' => $position['we_owe']['total'],
            'class' => 'border-warning',
            'lines' => [
                [__('app.reports.position.savings'), $position['we_owe']['savings'], null],
                [__('app.reports.position.fdr'), $position['we_owe']['fdr'], null],
            ],
        ],
        [
            'title' => __('app.reports.position.month', ['date' => Numerals::localize($month['from']->format('d/m/Y'))]),
            'total' => null,
            'class' => 'border-secondary',
            'lines' => [
                [__('app.reports.position.received'), $month['received'], route('admin.reports.monthly', ['month' => $month['from']->format('Y-m')])],
                [__('app.reports.position.paid_out'), $month['paid_out'], route('admin.reports.monthly', ['month' => $month['from']->format('Y-m')])],
                [__('app.reports.position.office_income'), $month['office_income'], route('admin.office-entries.index')],
                [__('app.reports.position.office_expense'), $month['office_expense'], route('admin.office-entries.index')],
            ],
        ],
    ];
@endphp
<div class="row g-3">
    @foreach ($sections as $section)
        <div class="col-md-6 col-xl-3">
            <div class="card h-100 border-top border-3 {{ $section['class'] }}">
                <div class="card-body pb-2">
                    <div class="fw-semibold">{{ $section['title'] }}</div>
                    @if ($section['total'])
                        <div class="fs-3 fw-bold text-nowrap">{{ $section['total']->format() }}</div>
                    @endif
                    @isset($section['note'])
                        <div class="small text-body-secondary">{{ $section['note'] }}</div>
                    @endisset
                </div>
                <ul class="list-group list-group-flush">
                    @foreach ($section['lines'] as [$label, $amount, $url])
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            @if ($url)
                                <a href="{{ $url }}">{{ $label }}</a>
                            @else
                                <span>{{ $label }}</span>
                            @endif
                            <span class="fw-semibold text-nowrap">{{ $amount->format() }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endforeach
</div>
