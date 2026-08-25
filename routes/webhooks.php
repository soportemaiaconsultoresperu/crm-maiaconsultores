<?php

declare(strict_types=1);

use App\Http\Controllers\GoogleCalendarWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| V2 Webhook endpoints (B11 — infrastructure stub)
|--------------------------------------------------------------------------
|
| Generic webhook stubs go through the `signed.webhook` middleware alias
| (see App\Http\Middleware\VerifyWebhookSignature), which validates the
| per-provider signature header, timestamp window and payload size before
| those controllers see the request.
|
| Google Calendar push notifications use channel headers/tokens instead
| of that body-HMAC middleware, so their route is registered separately
| below and validated by GoogleCalendarWebhookController.
|
| NO auth middleware here on purpose: webhooks are public; provider-specific
| verification is the auth.
|
*/

Route::post('webhooks/google/calendar', GoogleCalendarWebhookController::class)
    ->name('webhooks.google-calendar');

Route::middleware('signed.webhook')->prefix('webhooks')->group(function (): void {
    Route::post('/{provider}', function (string $provider): \Illuminate\Http\JsonResponse {
        return response()->json([
            'received' => true,
            'provider' => $provider,
            'note' => 'stub endpoint; provider handlers land in B13..B17',
        ]);
    })->where('provider', '[a-z_]+');
});