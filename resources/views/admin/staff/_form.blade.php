@php($editing = isset($member))

<div class="grid gap-4 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
        <form method="POST"
              action="{{ $editing ? route('admin.staff.update', $member) : route('admin.staff.store') }}">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-text-input name="name" label="Full name" :value="old('name', $editing ? $member->name : '')" required />
                </div>
                <div>
                    <x-text-input name="email" type="email" label="Email" :value="old('email', $editing ? $member->email : '')" required />
                </div>
                <div>
                    <x-text-input name="phone" label="Phone" :value="old('phone', $editing ? $member->phone : '')" />
                </div>
                <div>
                    <label for="role" class="block text-sm font-medium text-slate-700">Role</label>
                    <select id="role" name="role" required
                            class="mt-1 w-full rounded-lg border px-3 py-2 text-sm focus:ring-emerald-500 @error('role') border-rose-400 @enderror">
                        @foreach ($roleOptions as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(old('role', $editing ? $member->getRoleNames()->first() : 'staff') === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('role')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror

                    <div class="mt-1 space-y-1">
                        @foreach ($roles as $value => $meta)
                            <p class="text-xs text-slate-500"><span class="font-medium text-slate-700">{{ $meta['label'] }}:</span> {{ $meta['description'] }}</p>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-5 border-t border-slate-200 pt-5">
                <h3 class="text-sm font-semibold text-slate-900">{{ $editing ? 'Reset password' : 'Password' }}</h3>
                <p class="mt-0.5 text-xs text-slate-500">
                    @if ($editing)
                        Leave both fields blank to keep the current password.
                    @else
                        At least 8 characters. The new member signs in with this immediately.
                    @endif
                </p>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-text-input name="password" type="password" :required="! $editing" label="Password" />
                    </div>
                    <div>
                        <x-text-input name="password_confirmation" type="password" :required="! $editing" label="Confirm password" />
                    </div>
                </div>
            </div>

            <div class="mt-5 flex items-center gap-3 border-t border-slate-200 pt-5">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked(old('is_active', $editing ? $member->is_active : true))
                           class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    Account is active
                </label>

                <div class="ml-auto flex gap-2">
                    <a href="{{ route('admin.staff.index') }}"
                       class="rounded-lg border border-slate-300 px-5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </a>
                    <button type="submit" class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
                        {{ $editing ? 'Save changes' : 'Create account' }}
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="space-y-4">
        @if ($editing)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-2 text-sm font-semibold text-slate-900">Account</h3>
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Status</dt>
                        <dd class="font-medium {{ $member->is_active ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $member->is_active ? 'Active' : 'Inactive' }}
                        </dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-slate-500">Last sign-in</dt>
                        <dd class="font-medium">{{ $member->last_login_at?->format('d M Y H:i') ?? 'Never' }}</dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-slate-500">Created</dt>
                        <dd class="font-medium">{{ $member->created_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        @endif

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-1 text-sm font-semibold text-slate-900">Deactivate instead of deleting</h3>
            <p class="text-xs text-slate-500">
                A deactivated account cannot sign in, but the sales, refunds and stock
                movements it created stay attributed to it. Deleting is only offered for
                accounts that have never touched the till.
            </p>
        </div>
    </div>
</div>
