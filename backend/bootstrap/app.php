<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: session cookies + CSRF for requests from stateful (same-origin) domains.
        $middleware->statefulApi();
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias(['active' => EnsureUserIsActive::class]);
        // cPanel/Cloudflare proxies: trust forwarded headers so HTTPS and client IPs are correct.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
        // API clients get 401 JSON instead of a redirect to a non-existent login route.
        $middleware->redirectGuestsTo(fn (Request $r) => $r->expectsJson() || $r->is('api/*') ? null : '/admin/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
