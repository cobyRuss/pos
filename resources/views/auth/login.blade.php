<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In &middot; {{ config('app.name', 'POS System') }}</title>
    <link href="{{ asset('vendor/bootstrap-5.3.3/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons-1.11.3/bootstrap-icons.css') }}" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="card shadow-sm w-100" style="max-width: 420px;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <h1 class="h4 mb-1">{{ \App\Models\Setting::get('store_name', config('app.name')) }}</h1>
                <p class="text-body-secondary small mb-0">Sign in to access the point of sale</p>
            </div>

            @if (session('success'))
                <div class="alert alert-success py-2 small">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger py-2 small">{{ session('error') }}</div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" novalidate>
                @csrf

                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email') }}"
                               required autofocus autocomplete="username">
                    </div>
                    @error('email')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                               id="password" name="password" required autocomplete="current-password">
                    </div>
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                </button>
            </form>
        </div>
        <div class="card-footer bg-body-tertiary text-center small text-body-secondary">
            Access is restricted to authorised staff.
        </div>
    </div>
</div>

<script src="{{ asset('vendor/bootstrap-5.3.3/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
