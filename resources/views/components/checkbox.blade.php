@props(['name', 'label' => null, 'checked' => false, 'hint' => null])

<label class="flex items-start gap-2">
    <input type="hidden" name="{{ $name }}" value="0">

    <input type="checkbox" name="{{ $name }}" value="1"
           @checked(old($name, $checked))
           class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">

    <span>
        <span class="block text-sm font-medium text-slate-700">{{ $label }}</span>
        @if ($hint)
            <span class="block text-xs text-slate-500">{{ $hint }}</span>
        @endif
    </span>
</label>
