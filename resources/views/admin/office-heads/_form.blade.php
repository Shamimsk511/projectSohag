@csrf

<div class="card-body">
    <div class="row">
        <div class="col-md-5 mb-3">
            <label for="name" class="form-label">{{ __('app.office_heads.name') }}</label>
            <input type="text" id="name" name="name" value="{{ old('name', $head->name) }}"
                class="form-control @error('name') is-invalid @enderror" required maxlength="255" autofocus>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4 mb-3">
            <label for="kind" class="form-label">{{ __('app.office_heads.kind') }}</label>
            <select id="kind" name="kind" class="form-select @error('kind') is-invalid @enderror" required>
                @foreach (\App\Enums\TransactionType::officeTypes() as $kind)
                    <option value="{{ $kind->value }}" @selected(old('kind', $head->kind?->value ?? \App\Enums\TransactionType::OfficeIncome->value) === $kind->value)>
                        {{ __('app.transactions.types.'.$kind->value) }}
                    </option>
                @endforeach
            </select>
            @error('kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3 mb-3">
            <label for="status" class="form-label">{{ __('app.office_heads.status') }}</label>
            <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                @foreach (\App\Enums\ActiveStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $head->status?->value ?? \App\Enums\ActiveStatus::Active->value) === $status->value)>
                        {{ __('app.statuses.'.$status->value) }}
                    </option>
                @endforeach
            </select>
            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

<div class="card-footer d-flex gap-2">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg" aria-hidden="true"></i> {{ __('app.save') }}
    </button>
    <a href="{{ route('admin.office-heads.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
</div>
