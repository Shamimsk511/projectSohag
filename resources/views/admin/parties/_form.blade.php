@csrf

<div class="card-body">
    <div class="row">
        <div class="col-md-4 mb-3">
            <x-form.input name="client_number" :label="__('app.parties.client_number')" :value="$party->client_number"
                :hint="__('app.parties.client_number_hint')" maxlength="30" autofocus />
        </div>
        <div class="col-md-4 mb-3">
            <x-form.input name="name" :label="__('app.parties.name')" :value="$party->name" required maxlength="255" />
        </div>
        <div class="col-md-4 mb-3">
            <x-form.input name="phone" type="tel" :label="__('app.parties.phone')" :value="$party->phone"
                :hint="__('app.users.phone_hint')" inputmode="numeric" required />
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <x-form.input name="father_or_husband_name" :label="__('app.parties.father_or_husband_name')" :value="$party->father_or_husband_name" maxlength="255" />
        </div>
        <div class="col-md-6 mb-3">
            <x-form.input name="mother_name" :label="__('app.parties.mother_name')" :value="$party->mother_name" maxlength="255" />
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <x-form.input name="national_id" :label="__('app.parties.national_id')" :value="$party->national_id"
                :hint="__('app.parties.national_id_hint')" inputmode="numeric" />
        </div>
        <div class="col-md-4 mb-3">
            <x-form.input name="date_of_birth" type="date" :label="__('app.parties.date_of_birth')" :value="$party->date_of_birth?->toDateString()" />
        </div>
        <div class="col-md-4 mb-3">
            <label for="gender" class="form-label">{{ __('app.parties.gender') }}</label>
            <select id="gender" name="gender" class="form-select @error('gender') is-invalid @enderror">
                <option value="">{{ __('app.parties.not_set') }}</option>
                @foreach (\App\Enums\Gender::cases() as $gender)
                    <option value="{{ $gender->value }}" @selected(old('gender', $party->gender?->value) === $gender->value)>
                        {{ __('app.genders.'.$gender->value) }}
                    </option>
                @endforeach
            </select>
            @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="mb-3">
        <label for="address" class="form-label">{{ __('app.parties.address') }}</label>
        <textarea id="address" name="address" rows="2" maxlength="500" required
            class="form-control @error('address') is-invalid @enderror">{{ old('address', $party->address) }}</textarea>
        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="area_id" class="form-label">{{ __('app.parties.area') }}</label>
            <select id="area_id" name="area_id" required data-placeholder="{{ __('app.parties.select_area') }}"
                class="form-select js-select2 @error('area_id') is-invalid @enderror">
                <option value="">{{ __('app.parties.select_area') }}</option>
                @foreach ($areas as $area)
                    <option value="{{ $area->id }}" @selected((string) old('area_id', $party->area_id) === (string) $area->id)>{{ $area->name }}</option>
                @endforeach
            </select>
            @error('area_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 mb-3">
            <label for="status" class="form-label">{{ __('app.parties.status') }}</label>
            <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                @foreach (\App\Enums\ActiveStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $party->status?->value ?? \App\Enums\ActiveStatus::Active->value) === $status->value)>
                        {{ __('app.statuses.'.$status->value) }}
                    </option>
                @endforeach
            </select>
            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="mb-3">
        <label for="photo" class="form-label">{{ __('app.parties.photo') }}</label>
        @if ($party->photo_path)
            <div class="mb-2">
                <img src="{{ route('admin.parties.photo', $party) }}" alt="{{ $party->name }}" class="rounded border" width="96" height="96" style="object-fit: cover">
            </div>
        @endif
        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" class="js-filepond"
            data-process-url="{{ route('admin.uploads.party-photo.store') }}"
            data-revert-url="{{ route('admin.uploads.party-photo.destroy') }}"
            data-csrf="{{ csrf_token() }}"
            data-labels='@json(__('app.upload'))'>
        <div class="form-text">{{ $party->photo_path ? __('app.parties.photo_replace_hint') : __('app.parties.photo_hint') }}</div>
        @error('photo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <fieldset class="border rounded p-3">
        <legend class="float-none w-auto px-2 fs-6 mb-0">{{ __('app.parties.nominee') }}</legend>
        <div class="row">
            <div class="col-md-6 mb-3">
                <x-form.input name="nominee_name" :label="__('app.parties.nominee_name')" :value="$party->nominee_name" maxlength="255" />
            </div>
            <div class="col-md-6 mb-3">
                <x-form.input name="nominee_relation" :label="__('app.parties.nominee_relation')" :value="$party->nominee_relation" maxlength="100" />
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3 mb-md-0">
                <x-form.input name="nominee_phone" type="tel" :label="__('app.parties.nominee_phone')" :value="$party->nominee_phone" inputmode="numeric" />
            </div>
            <div class="col-md-6">
                <x-form.input name="nominee_national_id" :label="__('app.parties.nominee_national_id')" :value="$party->nominee_national_id" inputmode="numeric" />
            </div>
        </div>
    </fieldset>
</div>

<div class="card-footer d-flex gap-2">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg" aria-hidden="true"></i> {{ __('app.save') }}
    </button>
    <a href="{{ $party->exists ? route('admin.parties.show', $party) : route('admin.parties.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
</div>

@push('js')
    @vite('resources/js/admin/parties.js')
@endpush
