@php
    $editing = $category->exists;
@endphp

<div class="mb-3">
    <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name"
           value="{{ old('name', $category->name) }}" required maxlength="120" autofocus>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="description" class="form-label">Description</label>
    <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description"
              rows="3" maxlength="1000">{{ old('description', $category->description) }}</textarea>
    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
           @checked(old('is_active', $category->is_active ?? true))>
    <label class="form-check-label" for="is_active">Active &mdash; selectable when ringing up sales</label>
    @error('is_active')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
