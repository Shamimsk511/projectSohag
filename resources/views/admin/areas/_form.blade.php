@csrf

<div class="card-body">
    <div class="row">
        <div class="col-md-8 mb-3">
            <label for="name" class="form-label">{{ __('app.areas.name') }}</label>
            <input type="text" id="name" name="name" value="{{ old('name', $area->name) }}"
                class="form-control @error('name') is-invalid @enderror" required maxlength="255" autofocus>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4 mb-3">
            <label for="status" class="form-label">{{ __('app.areas.status') }}</label>
            <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                @foreach (\App\Enums\ActiveStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $area->status?->value ?? \App\Enums\ActiveStatus::Active->value) === $status->value)>
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
    <a href="{{ route('admin.areas.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
</div>
