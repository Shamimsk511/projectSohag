@csrf

<div class="card-body">
    <div class="mb-3">
        <label for="name" class="form-label">{{ __('app.users.name') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
            class="form-control @error('name') is-invalid @enderror" required maxlength="255" autofocus>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="email" class="form-label">{{ __('app.users.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}"
                class="form-control @error('email') is-invalid @enderror" required maxlength="255">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 mb-3">
            <label for="phone" class="form-label">{{ __('app.users.phone') }}</label>
            <input type="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" inputmode="numeric"
                class="form-control @error('phone') is-invalid @enderror" aria-describedby="phone-hint">
            <div id="phone-hint" class="form-text">{{ __('app.users.phone_hint') }}</div>
            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="role" class="form-label">{{ __('app.users.role') }}</label>
            <select id="role" name="role" class="form-select @error('role') is-invalid @enderror" required>
                @foreach (\App\Enums\Role::cases() as $role)
                    <option value="{{ $role->value }}" @selected(old('role', $user->currentRole()?->value ?? \App\Enums\Role::FieldOfficer->value) === $role->value)>
                        {{ __('app.roles.'.$role->value) }}
                    </option>
                @endforeach
            </select>
            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 mb-3">
            <label for="status" class="form-label">{{ __('app.users.status') }}</label>
            <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                @foreach (\App\Enums\UserStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $user->status?->value ?? \App\Enums\UserStatus::Active->value) === $status->value)>
                        {{ __('app.statuses.'.$status->value) }}
                    </option>
                @endforeach
            </select>
            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="password" class="form-label">{{ __('app.users.password') }}</label>
            <input type="password" id="password" name="password" autocomplete="new-password"
                class="form-control @error('password') is-invalid @enderror" @if (! $user->exists) required @endif>
            @if ($user->exists)
                <div class="form-text">{{ __('app.users.password_keep_hint') }}</div>
            @endif
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 mb-3">
            <label for="password_confirmation" class="form-label">{{ __('app.users.password_confirmation') }}</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password"
                class="form-control" @if (! $user->exists) required @endif>
        </div>
    </div>
</div>

<div class="card-footer d-flex gap-2">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg" aria-hidden="true"></i> {{ __('app.save') }}
    </button>
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
</div>
