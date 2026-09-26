@props([
    'label' => null,
    'name',
    'value' => null,
    'options' => [],
    'placeholder' => null,
    'includeBlank' => false,
    'blankLabel' => null,
])

@if ($label)
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">{{ $label }}</label>
@endif

<select id="{{ $name }}"
        name="{{ $name }}"
        @class([
            // The mt-1 only applies when a label sits above, matching
            // text-input so fields in the same filter row line up.
            $label ? 'mt-1 w-full rounded-lg border px-3 py-2 text-sm focus:ring-emerald-500' : 'w-full rounded-lg border px-3 py-2 text-sm focus:ring-emerald-500',
            'border-slate-300 focus:border-emerald-500',
            'border-rose-400' => $errors->has($name),
        ])>
    @if ($includeBlank)
        <option value="">{{ $blankLabel ?? 'All' }}</option>
    @endif

    @foreach ($options as $optionValue => $optionLabel)
        <option value="{{ $optionValue }}" @selected((string) old($name, $value) === (string) $optionValue)>
            {{ $optionLabel }}
        </option>
    @endforeach
</select>

@error($name)
    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
@enderror
