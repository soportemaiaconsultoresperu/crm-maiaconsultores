<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| V2 Webhook endpoints (B11 — infrastructure stub)
|--------------------------------------------------------------------------
|
| Every webhook request goes through the `signed.webhook` middleware
| alias (see App\Http\Middleware\VerifyWebhookSignature) which validates
| the per-provider signature header, the timestamp window and the
| payload size before any controller sees the request.
|
| The stub below is intentionally minimal: it acknowledges the delivery
| with 200 OK so we can exercise the middleware end-to-end in tests and
| in CI. Provider-specific endpoints (meta, google, outlook) will be
| added in B13..B17 once the corresponding adapters land.
|
| NO auth middleware here on purpose: webhooks are public, the signature
| IS the auth.
|
*/

Route::middleware('signed.webhook')->prefix('webhooks')->group(function (): void {
    Route::post('/{provider}', function (string $provider): \Illuminate\Http\JsonResponse {
        return response()->json([
            'received' => true,
            'provider' => $provider,
            'note' => 'stub endpoint; provider handlers land in B13..B17',
        ]);
    })->where('provider', '[a-z_]+');
});