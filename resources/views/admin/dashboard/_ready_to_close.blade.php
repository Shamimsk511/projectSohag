{{-- Paid-off loans waiting for a one-click close. Expects $readyToClose (ReportService::readyToClose). --}}
<div class="card card-outline card-success mb-4">
    <div class="card-header">
        <h2 class="card-title h5 mb-0">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            {{ __('app.dash.ready_to_close') }}
            <span class="badge text-bg-success ms-1">{{ count($readyToClose) }}</span>
        </h2>
    </div>
    <div class="card-body p-0">
        <p class="text-body-secondary small px-3 pt-3 mb-2">{{ __('app.dash.ready_to_close_hint') }}</p>
        @error('close_reason')
            <div class="alert alert-danger mx-3">{{ $message }}</div>
        @enderror
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.dash.member') }}</th>
                        <th>{{ __('app.accounts.number') }}</th>
                        <th class="text-end">{{ __('app.dash.contract') }}</th>
                        <th class="text-end">{{ __('app.dash.received') }}</th>
                        <th>{{ __('app.dash.last_paid') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($readyToClose as $line)
                        @php($account = $line['account'])
                        <tr>
                            <td>
                                <a href="{{ route('admin.parties.show', $account->party) }}">{{ $account->party->name }}</a>
                                <div class="small text-body-secondary">{{ $account->party->party_code }}</div>
                            </td>
                            <td><a href="{{ route('admin.accounts.show', $account) }}">{{ $account->account_number }}</a></td>
                            <td class="text-end">{{ $line['contract']->format() }}</td>
                            <td class="text-end">{{ $line['received']->format() }}</td>
                            <td class="text-nowrap">{{ $line['last_paid'] ? \App\Support\Numerals::localize($line['last_paid']->format('d/m/Y')) : '' }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('admin.accounts.close', $account) }}" class="js-confirm"
                                      data-confirm="{{ json_encode([
                                          'title' => __('app.assignments.confirm_title'),
                                          'text' => __('app.accounts.close_confirm'),
                                          'confirm' => __('app.accounts.confirm_yes'),
                                          'cancel' => __('app.cancel'),
                                      ]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                        <i class="bi bi-lock" aria-hidden="true"></i> {{ __('app.dash.close') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
