@props([
    'label' => null,
    'name',
    'value' => null,
    'type' => 'text',
    'hint' => null,
])

@php($hasError = $errors->has($name))

@if ($label)
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">{{ $label }}</label>
@endif

<input id="{{ $name }}"
       type="{{ $type }}"
       name="{{ $name }}"
       value="{{ old($name, $value) }}"
       @if ($hasError) aria-invalid="true" @endif
       {{ $attributes->merge([
           'class' => 'mt-1 w-full rounded-lg border px-3 py-2 text-sm focus:ring-emerald-500 '
               .($hasError
                   ? 'border-rose-400 focus:border-rose-400'
                   : 'border-slate-300 focus:border-emerald-500'),
       ]) }}>

@if ($hint)
    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
@endif

@error($name)
    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
@enderror
