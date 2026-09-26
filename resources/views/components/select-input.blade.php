@props([
    'label' => null,
    'name',
    'value' => null,
    'options' => [],
    'placeholder' => null,
    'includeBlank' => false,
    'blankLabel' => null,
])

<label class="block text-sm font-medium text-slate-700">{{ $label }}</label>

<select name="{{ $name }}"
        @class([
            'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500',
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
