@extends('layouts.admin')

@section('subtitle', __('app.cash.title'))
@section('content_header_title', __('app.cash.title'))

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-4 order-lg-2">
            @can('create', \App\Models\CashHandover::class)
                <form method="POST" action="{{ route('admin.cash-handovers.store') }}" class="card mb-3">
                    @csrf
                    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.cash.record') }}</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="field_officer_id" class="form-label">{{ __('app.cash.officer') }}</label>
                            <select id="field_officer_id" name="field_officer_id" required
                                    @class(['form-select', 'is-invalid' => $errors->has('field_officer_id')])>
                                <option value=""></option>
                                @foreach ($officers as $officer)
                                    <option value="{{ $officer->id }}" @selected((int) old('field_officer_id') === $officer->id)>
                                        {{ $officer->name }} ({{ $inHand[$officer->id]->format() }})
                                    </option>
                                @endforeach
                            </select>
                            @error('field_officer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <x-form.input name="amount" :label="__('app.cash.amount').' (৳)'" inputmode="decimal" autocomplete="off"
                                          class="form-control-lg" required />
                        </div>
                        <div class="mb-3">
                            <x-form.input name="handover_date" type="date" :label="__('app.cash.handover_date')"
                                          :value="$today" :max="$today" required />
                        </div>
                        <x-form.input name="note" :label="__('app.cash.note')" maxlength="500" autocomplete="off" />
                        <p class="small text-body-secondary mt-3 mb-0">{{ __('app.cash.record_hint') }}</p>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary w-100">{{ __('app.cash.record') }}</button>
                    </div>
                </form>
            @endcan
        </div>

        <div class="col-lg-8 order-lg-1">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.cash.in_hand') }}</h3></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('app.cash.officer') }}</th>
                                <th class="text-end">{{ __('app.cash.in_hand') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($officers as $officer)
                                <tr>
                                    <td>{{ $officer->name }}</td>
                                    <td @class(['text-end text-nowrap fw-semibold', 'text-danger' => $inHand[$officer->id]->isNegative()])>
                                        {{ $inHand[$officer->id]->format() }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="text-center text-body-secondary py-4">{{ __('app.cash.no_officers') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-body-secondary">{{ __('app.cash.in_hand_hint') }}</div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.cash.recent') }}</h3></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('app.cash.handover_date') }}</th>
                                <th>{{ __('app.cash.officer') }}</th>
                                <th class="text-end">{{ __('app.cash.amount') }}</th>
                                <th>{{ __('app.cash.confirmed_by') }}</th>
                                <th>{{ __('app.cash.note') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($handovers as $handover)
                                <tr>
                                    <td class="text-nowrap">{{ \App\Support\Numerals::localize($handover->handover_date->format('d/m/Y')) }}</td>
                                    <td>{{ $handover->fieldOfficer->name }}</td>
                                    <td class="text-end text-nowrap">{{ $handover->amount->format() }}</td>
                                    <td>{{ $handover->confirmedBy->name }}</td>
                                    <td>{{ $handover->note }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-body-secondary py-4">{{ __('app.cash.empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($handovers->hasPages())
                    <div class="card-footer">{{ $handovers->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@stop
