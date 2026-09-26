<div {{ $attributes->merge(['class' => 'mt-1 flex flex-wrap items-center gap-2']) }}>
    @if ($title ?? false)
        <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>
    @endif

    {{ $slot }}
</div>
