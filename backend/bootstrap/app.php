<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // every /api response is JSON (see ForceJsonResponse)
        $middleware->api(prepend: [ForceJsonResponse::class]);

        // short names used in routes/api.php
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'active' => EnsureUserIsActive::class,
        ]);

        // Behind a reverse proxy (e.g. Vercel rewrites in production) every request seems to come
        // from the proxy's IP, so all users would share one login rate limit. TRUSTED_PROXIES=*
        // makes Laravel read the real client IP from X-Forwarded-For. Leave it unset locally.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // errors on /api/* (404, 403, 422, 500...) are rendered as JSON, never as HTML pages
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
    })->create();