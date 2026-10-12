{{-- Field officer card on the party profile: current officer, (re)assign form and history. Expects $date from show. --}}
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.parties.field_officer') }}</h3></div>
    <div class="card-body">
        @if ($currentAssignment)
            <div class="d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-person-badge fs-4 text-primary" aria-hidden="true"></i>
                <div>
                    <div class="fw-semibold">{{ $currentAssignment->fieldOfficer->name }}</div>
                    <small class="text-body-secondary">{{ __('app.assignments.since', ['date' => $date($currentAssignment->assigned_from)]) }}</small>
                </div>
            </div>
        @else
            <p class="text-body-secondary">{{ __('app.parties.not_assigned') }}</p>
        @endif

        @can('assign', $party)
            @if ($party->isActive())
                <form method="POST" action="{{ route('admin.parties.assignment.store', $party) }}">
                    @csrf
                    <label for="field_officer_id" class="form-label visually-hidden">{{ __('app.assignments.select_officer') }}</label>
                    <select id="field_officer_id" name="field_officer_id" required
                            @class(['form-select js-select2', 'is-invalid' => $errors->has('field_officer_id')])
                            data-placeholder="{{ __('app.assignments.select_officer') }}">
                        <option value=""></option>
                        @foreach ($officers as $officer)
                            <option value="{{ $officer->id }}" @selected((int) old('field_officer_id') === $officer->id)
                                    @disabled($currentAssignment?->field_officer_id === $officer->id)>
                                {{ $officer->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('field_officer_id')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <button type="submit" class="btn btn-primary w-100 mt-2">
                        <i class="bi bi-person-check" aria-hidden="true"></i>
                        {{ $currentAssignment ? __('app.assignments.reassign') : __('app.assignments.assign') }}
                    </button>
                </form>
            @else
                @error('field_officer_id')
                    <div class="alert alert-danger py-2">{{ $message }}</div>
                @enderror
            @endif

            @if ($currentAssignment)
                <form method="POST" action="{{ route('admin.parties.assignment.destroy', $party) }}" class="mt-2 js-confirm"
                      data-confirm="{{ json_encode([
                          'title' => __('app.assignments.confirm_title'),
                          'text' => __('app.assignments.end_confirm'),
                          'confirm' => __('app.assignments.confirm_yes'),
                          'cancel' => __('app.cancel'),
                      ]) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger w-100">
                        <i class="bi bi-person-x" aria-hidden="true"></i> {{ __('app.assignments.end') }}
                    </button>
                </form>
            @endif
        @endcan
    </div>

    @if ($party->assignments->isNotEmpty())
        <div class="card-footer">
            <h4 class="h6">{{ __('app.assignments.history') }}</h4>
            <ul class="list-unstyled mb-0 small">
                @foreach ($party->assignments as $assignment)
                    <li class="py-1 border-bottom">
                        <span class="fw-semibold">{{ $assignment->fieldOfficer->name }}</span>
                        @if ($assignment->isActive())
                            <span class="badge text-bg-success">{{ __('app.assignments.current') }}</span>
                        @endif
                        <div class="text-body-secondary">
                            {{ $assignment->isActive()
                                ? __('app.assignments.since', ['date' => $date($assignment->assigned_from)])
                                : __('app.assignments.period', ['from' => $date($assignment->assigned_from), 'until' => $date($assignment->assigned_until)]) }}
                            · {{ $assignment->assignedBy->name }}
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

@can('assign', $party)
    @push('js')
        @vite('resources/js/admin/parties.js')
    @endpush
@endcan
