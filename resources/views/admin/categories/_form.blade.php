@php($editing = $category->exists)

<form method="POST"
      action="{{ $editing ? route('admin.categories.update', $category) : route('admin.categories.store') }}"
      class="mx-auto max-w-2xl space-y-6">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <div class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-base font-semibold text-slate-900">Category</h2>

        <div class="mt-4 space-y-4">
            <x-text-input name="name" label="Name" :value="$category->name" required maxlength="255" />

            <x-text-input name="slug" label="URL slug" :value="$category->slug"
                          hint="Leave blank to generate it from the name." maxlength="255" />

            <x-textarea-input name="description" label="Description" :value="$category->description" rows="3" />

            <x-checkbox name="is_active" label="Active"
                        :checked="$category->exists ? $category->is_active : true"
                        hint="Hidden categories are not offered on the POS terminal." />
        </div>
    </div>

    <div class="flex items-center justify-end gap-3">
        <a href="{{ route('admin.categories.index') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
            Cancel
        </a>
        <button type="submit"
                class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
            {{ $editing ? 'Save changes' : 'Create category' }}
        </button>
    </div>
</form>
