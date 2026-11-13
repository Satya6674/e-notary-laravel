@props(['name', 'label', 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null, 'id' => null])
@php($fid = $id ?? 'f-'.$name)
@php($eid = $id ? 'e-'.$id : 'e-'.$name)
<div class="mb-3">
    <label class="form-label" for="{{ $fid }}">{{ $label }}@if($required) <span class="en-required" aria-hidden="true">*</span>@endif</label>
    <input id="{{ $fid }}" name="{{ $name }}" type="{{ $type }}" @if($type !== 'password') value="{{ old($name, $value) }}" @endif @required($required)
        @error($name) aria-invalid="true" aria-describedby="{{ $eid }}" @enderror
        {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>
    @if($hint)<div class="form-text">{{ $hint }}</div>@endif
    @error($name)<div class="invalid-feedback" id="{{ $eid }}">{{ $message }}</div>@enderror
</div>
