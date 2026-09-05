<?php

declare(strict_types=1);

use App\Http\Middleware\EstablishTenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Order matters, and it is load-bearing.
         *
         *   EstablishTenantContext   resolves the organisation and publishes
         *                            it to PostgreSQL for row-level security
         *   HandleInertiaRequests    shares it with the frontend
         *
         * The tenant context must be established before anything queries an
         * organisation-scoped model, and the Inertia middleware must run
         * after it so the shared props see a resolved organisation.
         */
        $middleware->web(append: [
            EstablishTenantContext::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Trust the reverse proxy for forwarded headers. Without this every
        // request appears to come from Caddy, which makes per-IP rate
        // limiting useless and puts the wrong address in the audit log.
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            // Webhooks authenticate by signature, not by session. Added in
            // Phase 10; listed here so the exception is reviewed in one place.
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Never leak internals to a browser. Laravel's default already avoids
         * stack traces in production, but domain exceptions can carry detail
         * in their messages — an account code, a customer name — that has no
         * business in an HTTP response.
         */
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'two_factor_code',
            'two_factor_recovery_code',
        ]);
    })
    ->create();
