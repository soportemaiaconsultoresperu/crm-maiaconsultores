<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Mount the V2 webhook route file (B11). Real provider
            // endpoints are added in B13..B17. The file is included
            // here instead of via withRouting(routes:) so we keep the
            // option open for future API/web route files without
            // changing the bootstrap signature.
            $webhooks = __DIR__.'/../routes/webhooks.php';
            if (file_exists($webhooks)) {
                require $webhooks;
            }
        },
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'signed.webhook' => VerifyWebhookSignature::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson(),
        );
    })->create();
