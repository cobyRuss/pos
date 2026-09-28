@php
    $isSelf = $isSelf ?? false;
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="name" class="form-label">Full name <span class="text-danger">*</span></label>
            <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                   value="{{ old('name', $staff->name) }}" required maxlength="255" autofocus>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-md-6">
        <div class="mb-3">
            <label for="email" class="form-label">Email address <span class="text-danger">*</span></label>
            <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email"
                   value="{{ old('email', $staff->email) }}" required maxlength="255" autocomplete="off">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-md-6">
        <div class="mb-3">
            <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
            <select class="form-select @error('role') is-invalid @enderror" id="role" name="role" required
                    @if ($isSelf) disabled @endif>
                @foreach ($roles as $value => $label)
                    <option value="{{ $value }}" @selected(old('role', $staff->role?->value) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($isSelf)
                <input type="hidden" name="role" value="{{ $staff->role->value }}">
                <div class="form-text">You cannot change your own role.</div>
            @endif
            <div class="form-text">
                <strong>Administrator</strong> gets full access; <strong>Staff</strong> can only ring up sales, view the
                catalog and their own orders.
            </div>
            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-md-6">
        <div class="mb-3">
            <label for="phone" class="form-label">Phone</label>
            <input type="text" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone"
                   value="{{ old('phone', $staff->phone) }}" maxlength="30">
            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-12">
        <div class="mb-3">
            <label for="address" class="form-label">Address</label>
            <textarea class="form-control @error('address') is-invalid @enderror" id="address" name="address"
                      rows="2" maxlength="1000">{{ old('address', $staff->address) }}</textarea>
            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-md-6">
        <div class="mb-3">
            <label for="password" class="form-label">
                Password @unless ($staff->exists)<span class="text-danger">*</span>@endunless
            </label>
            <input type="password" class="form-control @error('password') is-invalid @enderror" id="password"
                   name="password" autocomplete="new-password" @unless ($staff->exists) required @endunless>
            <div class="form-text">
                @if ($staff->exists)
                    Leave blank to keep the current password. Minimum 8 characters.
                @else
                    Minimum 8 characters.
                @endif
            </div>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-md-6">
        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirm password</label>
            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                   autocomplete="new-password" @unless ($staff->exists) required @endunless>
        </div>
    </div>

    <div class="col-12">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                   @checked(old('is_active', $staff->is_active ?? true))
                   @if ($isSelf) disabled @endif>
            <label class="form-check-label" for="is_active">Active &mdash; can sign in to the till</label>
            @if ($isSelf)
                <input type="hidden" name="is_active" value="1">
                <div class="form-text">You cannot deactivate your own account.</div>
            @endif
            @error('is_active')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
    </div>
</div>
