{{-- Optional fee taken from the member with this entry (saved as its own office income row with a receipt). Expects $incomeHeads. --}}
<fieldset class="mb-3" data-fee-fields>
    <details @if (old('fee_amount') || $errors->hasAny(['fee_head_id', 'fee_amount'])) open @endif>
        <summary class="fw-semibold text-primary py-2">
            <i class="bi bi-plus-circle" aria-hidden="true"></i> {{ __('app.office_heads.fee_toggle') }}
        </summary>
        <p class="form-text mt-0">{{ __('app.office_heads.fee_hint') }}</p>
        <div class="row g-3">
            <div class="col-sm-6">
                <label for="fee_head_id" class="form-label fw-semibold">{{ __('app.office_heads.fee_head') }}</label>
                <select id="fee_head_id" name="fee_head_id" @class(['form-select', 'is-invalid' => $errors->has('fee_head_id')])>
                    <option value="">—</option>
                    @foreach ($incomeHeads as $head)
                        <option value="{{ $head->id }}" @selected((string) old('fee_head_id') === (string) $head->id)>{{ $head->name }}</option>
                    @endforeach
                </select>
                @error('fee_head_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6">
                <label for="fee_amount" class="form-label fw-semibold">{{ __('app.office_heads.fee_amount') }}</label>
                <div class="input-group">
                    <span class="input-group-text">৳</span>
                    <input type="text" id="fee_amount" name="fee_amount" value="{{ old('fee_amount') }}" inputmode="decimal" autocomplete="off"
                           @class(['form-control', 'is-invalid' => $errors->has('fee_amount')])>
                    @error('fee_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </details>
</fieldset>
