{{-- From/to filter for a period report. Expects $route, $from, $to, $prefix (lang key prefix under app.reports). --}}
<form method="GET" action="{{ route($route) }}" class="card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-sm-6 col-lg-3">
            <label for="from" class="form-label">{{ __($prefix.'.from') }}</label>
            <input type="date" id="from" name="from" value="{{ $from->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('from')])>
        </div>
        <div class="col-sm-6 col-lg-3">
            <label for="to" class="form-label">{{ __($prefix.'.to') }}</label>
            <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('to')])>
            @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-sm-6 col-lg-3">
            <button type="submit" class="btn btn-primary w-100">{{ __($prefix.'.show') }}</button>
        </div>
    </div>
</form>
