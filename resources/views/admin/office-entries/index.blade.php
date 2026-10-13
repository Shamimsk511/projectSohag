@extends('layouts.admin')

@section('subtitle', __('app.office_entries.title'))
@section('content_header_title', __('app.office_entries.title'))

@section('content_body')
    @php
        $date = fn (\Illuminate\Support\Carbon $value): string => \App\Support\Numerals::localize($value->format('d/m/Y'));
        $canCreate = auth()->user()->can('createOffice', \App\Models\Transaction::class);
        $reversible = fn (\App\Models\Transaction $entry): bool => $entry->reversal === null
            && auth()->user()->can('reverse', $entry);
        $showActions = $entries->getCollection()->contains($reversible);
        $today = today(config('app.display_timezone'))->toDateString();
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @error('reason')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    <div class="row">
        @if ($canCreate)
            <div class="col-lg-4">
                <form method="POST" action="{{ route('admin.office-entries.store') }}" class="card mb-3 js-confirm"
                      data-confirm="{{ json_encode([
                          'title' => __('app.transactions.confirm_title'),
                          'text' => __('app.transactions.confirm_text'),
                          'confirm' => __('app.transactions.confirm_yes'),
                          'cancel' => __('app.cancel'),
                      ]) }}">
                    @csrf
                    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.office_entries.new') }}</h3></div>
                    <div class="card-body">
                        <fieldset class="mb-3">
                            <legend class="form-label fs-6">{{ __('app.transactions.type') }}</legend>
                            <div class="btn-group w-100" role="group">
                                @foreach (\App\Enums\TransactionType::officeTypes() as $officeType)
                                    <input type="radio" class="btn-check" name="type" id="type-{{ $officeType->value }}" value="{{ $officeType->value }}"
                                           autocomplete="off" required @checked(old('type', 'office_expense') === $officeType->value)>
                                    <label class="btn btn-outline-primary py-2" for="type-{{ $officeType->value }}">
                                        {{ __('app.transactions.types.'.$officeType->value) }}
                                    </label>
                                @endforeach
                            </div>
                            @error('type')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </fieldset>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-baseline">
                                <label for="office_head_id" class="form-label">{{ __('app.office_heads.head') }}</label>
                                @can('create', \App\Models\OfficeHead::class)
                                    <a href="{{ route('admin.office-heads.create') }}" class="small">
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ __('app.office_heads.create') }}
                                    </a>
                                @endcan
                            </div>
                            <select id="office_head_id" name="office_head_id" @class(['form-select', 'is-invalid' => $errors->has('office_head_id')])>
                                <option value="">{{ __('app.office_heads.none') }}</option>
                                @foreach ($heads as $head)
                                    <option value="{{ $head->id }}" data-kind="{{ $head->kind->value }}" @selected((string) old('office_head_id') === (string) $head->id)>{{ $head->name }}</option>
                                @endforeach
                            </select>
                            @error('office_head_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <x-form.input name="amount" :label="__('app.transactions.amount').' (৳)'" inputmode="decimal" autocomplete="off"
                                          class="form-control-lg" required />
                        </div>
                        <div class="mb-3">
                            <x-form.input name="transaction_date" type="date" :label="__('app.transactions.date')"
                                          :value="$today" :max="$today" required />
                        </div>
                        <div class="mb-3">
                            <label for="payment_method" class="form-label">{{ __('app.transactions.payment_method') }}</label>
                            <select id="payment_method" name="payment_method" required
                                    @class(['form-select', 'is-invalid' => $errors->has('payment_method')])>
                                @foreach (\App\Enums\PaymentMethod::cases() as $method)
                                    <option value="{{ $method->value }}" @selected(old('payment_method', 'cash') === $method->value)>
                                        {{ __('app.transactions.payment_methods.'.$method->value) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <x-form.input name="reference" :label="__('app.transactions.reference')" :hint="__('app.transactions.reference_hint')"
                                          maxlength="100" autocomplete="off" />
                        </div>
                        <label for="description" class="form-label">{{ __('app.office_entries.description') }}</label>
                        <textarea id="description" name="description" rows="2" maxlength="500" required aria-describedby="description-hint"
                                  @class(['form-control', 'is-invalid' => $errors->has('description')])>{{ old('description') }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div id="description-hint" class="form-text">{{ __('app.office_entries.description_hint') }}</div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-success btn-lg w-100">{{ __('app.transactions.submit') }}</button>
                    </div>
                </form>
            </div>
        @endif

        <div @class(['col-lg-8' => $canCreate, 'col-12' => ! $canCreate])>
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.office-entries.index') }}" class="row g-2 align-items-end">
                        <div class="col-sm-4">
                            <x-form.input name="from" type="date" :label="__('app.accounts.statement_from')" :value="$from->toDateString()" />
                        </div>
                        <div class="col-sm-4">
                            <x-form.input name="to" type="date" :label="__('app.accounts.statement_to')" :value="$to->toDateString()" />
                        </div>
                        <div class="col-sm-4">
                            <button type="submit" class="btn btn-primary">{{ __('app.accounts.statement_filter') }}</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-sm-4">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-body-secondary">{{ __('app.office_entries.income_total') }}</div>
                        <div class="fs-5 fw-semibold text-success">{{ $totals['income']->format() }}</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-body-secondary">{{ __('app.office_entries.expense_total') }}</div>
                        <div class="fs-5 fw-semibold text-danger">{{ $totals['expense']->format() }}</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-body-secondary">{{ __('app.office_entries.net_total') }}</div>
                        <div class="fs-5 fw-semibold">{{ $totals['income']->minus($totals['expense'])->format() }}</div>
                    </div>
                </div>
                <div class="col-12 small text-body-secondary">{{ __('app.office_entries.totals_hint') }}</div>
            </div>

            @if ($headTotals)
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.office_heads.totals_title') }}</h3></div>
                    <table class="table table-sm mb-0">
                        <tbody>
                            @foreach ($headTotals as $line)
                                <tr>
                                    <td>{{ $line['head']?->name ?? __('app.office_heads.none') }}</td>
                                    <td class="text-body-secondary">{{ __('app.transactions.types.'.$line['kind']->value) }}</td>
                                    <td class="text-end text-nowrap">{{ $line['total']->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('app.transactions.date') }}</th>
                                <th>{{ __('app.transactions.type') }}</th>
                                <th>{{ __('app.transactions.reference') }}</th>
                                <th class="text-end">{{ __('app.transactions.amount') }}</th>
                                @if ($showActions)
                                    <th><span class="visually-hidden">{{ __('app.transactions.reverse') }}</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $entry)
                                <tr @class(['text-body-secondary' => $entry->reversal])>
                                    <td class="text-nowrap">{{ $date($entry->transaction_date) }}</td>
                                    <td>
                                        {{ __('app.transactions.types.'.$entry->type->value) }}
                                        @if ($entry->reversal)
                                            <span class="badge text-bg-warning">{{ __('app.accounts.statement_reversed') }}</span>
                                        @endif
                                        @if ($entry->officeHead)
                                            <span class="badge text-bg-light border">{{ $entry->officeHead->name }}</span>
                                        @endif
                                        @if ($entry->party && $entry->account)
                                            <div class="small">
                                                {{ __('app.office_heads.client') }}:
                                                <a href="{{ route('admin.accounts.show', $entry->account) }}">{{ $entry->party->name }} · {{ $entry->account->account_number }}</a>
                                            </div>
                                        @endif
                                        <div class="small text-body-secondary">{{ $entry->description }}</div>
                                        @if ($entry->reversal)
                                            <div class="small text-body-secondary">
                                                {{ __('app.office_entries.reversed_on', ['date' => $date($entry->reversal->transaction_date), 'reason' => $entry->reversal->description]) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        {{ $entry->reference }}
                                        @if ($entry->payment_method)
                                            <div class="small text-body-secondary">{{ __('app.transactions.payment_methods.'.$entry->payment_method->value) }}</div>
                                        @endif
                                    </td>
                                    <td @class(['text-end text-nowrap', 'text-decoration-line-through' => $entry->reversal])>{{ $entry->amount->format() }}</td>
                                    @if ($showActions)
                                        <td class="text-end">
                                            @if ($reversible($entry))
                                                <form method="POST" action="{{ route('admin.transactions.reverse', $entry) }}" class="js-confirm"
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
                                    <td colspan="{{ $showActions ? 5 : 4 }}" class="text-body-secondary">{{ __('app.office_entries.empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($entries->hasPages())
                    <div class="card-footer">{{ $entries->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    @push('js')
        @vite('resources/js/admin/office-entries.js')
    @endpush
@endsection
