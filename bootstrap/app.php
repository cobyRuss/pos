<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RedirectIfAuthenticated;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Browsers get a flash message and a bounce to a page they can actually see.
 * The intended URL is deliberately not stored, so a denied user cannot bounce
 * back into the forbidden screen after logging in.
 */
$denyWeb = function (Request $request) {
    if ($request->hasSession()) {
        $request->session()->flash('error', 'Unauthorized action.');
    }

    return Auth::check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        // Invalidate other sessions whenever the account password changes.
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'guest' => RedirectIfAuthenticated::class,
            'active' => EnsureUserIsActive::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) use ($denyWeb): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthorizationException|UnauthorizedException $exception, Request $request) use ($denyWeb) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthorized action.'], 403);
            }

            return $denyWeb($request);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest(route('login'));
        });

        $exceptions->render(function (HttpException $exception, Request $request) use ($denyWeb) {
            if ($exception->getStatusCode() !== 403 || $request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            return $denyWeb($request);
        });
    })->create();
