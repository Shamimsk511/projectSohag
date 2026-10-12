@extends('layouts.admin')

@section('subtitle', __('app.schedules.title'))
@section('content_header_title', __('app.schedules.title'))

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php($unassigned = \App\Http\Requests\Admin\ScheduleIndexRequest::UNASSIGNED)

    <form method="GET" action="{{ route('admin.schedules.index') }}" class="card mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label for="date" class="form-label">{{ __('app.schedules.due_date') }}</label>
                <input type="date" id="date" name="date" value="{{ $day->toDateString() }}" class="form-control">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="officer" class="form-label">{{ __('app.schedules.officer') }}</label>
                <select id="officer" name="officer" class="form-select">
                    <option value="">{{ __('app.schedules.all') }}</option>
                    <option value="{{ $unassigned }}" @selected($officer === $unassigned)>{{ __('app.schedules.unassigned') }}</option>
                    @foreach ($officers as $option)
                        <option value="{{ $option->id }}" @selected($officer === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="status" class="form-label">{{ __('app.schedules.status') }}</label>
                <select id="status" name="status" class="form-select">
                    <option value="">{{ __('app.schedules.all') }}</option>
                    @foreach (\App\Enums\ScheduleStatus::cases() as $case)
                        <option value="{{ $case->value }}" @selected($status === $case)>{{ __('app.schedules.statuses.'.$case->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel" aria-hidden="true"></i> {{ __('app.schedules.filter') }}
                </button>
            </div>
        </div>
    </form>

    <div class="row">
        <div @class(['col-lg-8' => $canManage && $officer !== null && ! $isPast, 'col-12' => ! ($canManage && $officer !== null && ! $isPast)])>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    <span>
                        {{ __('app.schedules.row_count', [
                            'count' => \App\Support\Numerals::localize((string) $schedules->total()),
                            'amount' => $total->format(),
                        ]) }}
                    </span>
                    <a href="{{ route('admin.schedules.print', array_filter(['date' => $day->toDateString(), 'officer' => $officer, 'status' => $status?->value])) }}"
                       target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.schedules.print') }}
                    </a>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('app.schedules.party') }}</th>
                                <th>{{ __('app.schedules.account') }}</th>
                                <th>{{ __('app.schedules.officer') }}</th>
                                <th class="text-end">{{ __('app.schedules.expected_amount') }}</th>
                                <th>{{ __('app.schedules.status') }}</th>
                                <th>{{ __('app.schedules.notes') }}</th>
                                @if ($canManage)
                                    <th></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($schedules as $schedule)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.parties.show', $schedule->party) }}">{{ $schedule->party->name }}</a>
                                        <div class="small text-body-secondary">{{ $schedule->party->party_code }}</div>
                                    </td>
                                    <td><a href="{{ route('admin.accounts.show', $schedule->account) }}">{{ $schedule->account->account_number }}</a></td>
                                    <td>{{ $schedule->fieldOfficer?->name ?? __('app.schedules.unassigned') }}</td>
                                    <td class="text-end text-nowrap">{{ $schedule->expected_amount->format() }}</td>
                                    <td>{{ __('app.schedules.statuses.'.$schedule->status->value) }}</td>
                                    <td class="small">{{ $schedule->notes }}</td>
                                    @if ($canManage)
                                        <td class="text-end">
                                            @if ($schedule->status === \App\Enums\ScheduleStatus::Pending && ! $isPast)
                                                <a href="{{ route('admin.schedules.edit', $schedule) }}" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i> {{ __('app.edit') }}
                                                </a>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-body-secondary py-4">{{ __('app.schedules.empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($schedules->hasPages())
                    <div class="card-footer">{{ $schedules->links() }}</div>
                @endif
            </div>
        </div>

        @if ($canManage && $officer !== null && ! $isPast)
            <div class="col-lg-4">
                <form method="POST" action="{{ route('admin.schedules.reassign') }}" class="card mb-3">
                    @csrf
                    <input type="hidden" name="date" value="{{ $day->toDateString() }}">
                    <input type="hidden" name="from_officer_id" value="{{ $officer }}">
                    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.schedules.reassign') }}</h3></div>
                    <div class="card-body">
                        <label for="to_officer_id" class="form-label">{{ __('app.schedules.reassign_to') }}</label>
                        <select id="to_officer_id" name="to_officer_id" required
                                @class(['form-select', 'is-invalid' => $errors->has('to_officer_id')])>
                            <option value=""></option>
                            @foreach ($activeOfficers as $option)
                                @continue($officer === $option->id)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('to_officer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @error('date')<div class="text-danger small">{{ $message }}</div>@enderror
                        <p class="small text-body-secondary mt-2 mb-0">{{ __('app.schedules.reassign_hint') }}</p>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-arrow-left-right" aria-hidden="true"></i> {{ __('app.schedules.reassign') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </div>
@stop
