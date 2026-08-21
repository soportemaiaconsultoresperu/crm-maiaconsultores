<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Email\EmailProvider;
use App\Contracts\Email\EmailProviderFactory;
use App\Models\IntegrationAccount;
use App\Services\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * B13 Pasada B — Inbound webhook endpoints for Gmail + Outlook.
 *
 * No auth middleware by design (per D-23+ B13 alignment): the signature
 * is the gate. Webhooks land on:
 *
 *   POST webhooks/email/gmail    → {@see self::gmail()}
 *   POST webhooks/email/outlook  → {@see self::outlook()}
 */
class EmailWebhookController extends Controller
{
    public function __construct(
        public readonly EmailService $emailService,
        public readonly EmailProviderFactory $factory,
    ) {
    }

    public function gmail(Request $request): JsonResponse
    {
        $account = IntegrationAccount::query()
            ->where('provider', 'gmail')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if ($account === null) {
            return response()->json([
                'ok' => false,
                'error_class' => 'NoBoundAccount',
                'error_message' => 'No active Gmail account configured.',
            ], 503);
        }

        $provider = $this->factory->for($account);

        if (! $provider->verifyWebhookSignature($request)) {
            return response()->json([
                'ok' => false,
                'error_class' => 'InvalidSignature',
                'error_message' => 'Webhook signature verification failed.',
            ], 400);
        }

        return $this->processInbound($provider);
    }

    public function outlook(Request $request): JsonResponse
    {
        $account = IntegrationAccount::query()
            ->where('provider', 'outlook')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if ($account === null) {
            return response()->json([
                'ok' => false,
                'error_class' => 'NoBoundAccount',
                'error_message' => 'No active Outlook account configured.',
            ], 503);
        }

        $provider = $this->factory->for($account);

        if (! $provider->verifyWebhookSignature($request)) {
            return response()->json([
                'ok' => false,
                'error_class' => 'InvalidSignature',
                'error_message' => 'Webhook signature verification failed.',
            ], 400);
        }

        return $this->processInbound($provider);
    }

    private function processInbound(EmailProvider $provider): JsonResponse
    {
        $drafts = $provider->fetchInbound();
        $persisted = [];
        foreach ($drafts as $draft) {
            $persisted[] = $this->emailService->handleInbound($draft)->id;
        }

        return response()->json([
            'ok' => true,
            'received' => count($persisted),
        ]);
    }
}
