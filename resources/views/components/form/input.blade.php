{{-- Labelled Bootstrap input with validation feedback. Extra attributes pass through to the <input>. --}}
@props(['name', 'label', 'value' => null, 'type' => 'text', 'hint' => null])

<label for="{{ $name }}" class="form-label">{{ $label }}</label>
<input type="{{ $type }}" id="{{ $name }}" name="{{ $name }}" value="{{ old($name, $value) }}"
    {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}
    @if ($hint) aria-describedby="{{ $name }}-hint" @endif>
@if ($hint)
    <div id="{{ $name }}-hint" class="form-text">{{ $hint }}</div>
@endif
@error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
