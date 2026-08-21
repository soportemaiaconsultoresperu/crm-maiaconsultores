<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationExecutionStep;
use App\Models\IntegrationAccount;
use App\Services\Automation\Exceptions\NotImplementedException;
use InvalidArgumentException;

/**
 * Send a WhatsApp template via the (future) WhatsAppProvider.
 *
 * B12 status: STUB. The Meta WhatsApp adapter is delivered in B14; this
 * action throws NotImplementedException until then. The exception is
 * caught by the engine which records `error_class=NotImplementedException`
 * without crashing the rest of the automation pipeline.
 *
 * Payload (forward-compatible):
 *  - template_name (string, required)
 *  - language (string, optional — default 'es_PE')
 *  - phone_number (string, required)
 *  - variables (array, optional)
 *  - account_id (int, optional — provider account; default: first active
 *    whatsapp IntegrationAccount)
 */
class SendWhatsAppTemplateAction implements ActionContract
{
    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $this->assertPayload($payload);

        $account = $this->resolveAccount($payload);

        if ($account === null) {
            throw new InvalidArgumentException(
                'SendWhatsAppTemplateAction: no active WhatsApp integration account is configured.'
            );
        }

        // B12: defer the real HTTP call to B14. Throwing a typed exception
        // lets the engine record the failure cleanly.
        throw new NotImplementedException(
            'WhatsApp provider is not yet implemented; expected in B14.'
        );
    }

    public function simulate(array $payload): array
    {
        return [
            'would_send_whatsapp' => true,
            'payload' => $payload,
        ];
    }

    private function assertPayload(array $payload): void
    {
        foreach (['template_name', 'phone_number'] as $field) {
            if (empty($payload[$field])) {
                throw new InvalidArgumentException(
                    "SendWhatsAppTemplateAction: {$field} is required."
                );
            }
        }
    }

    private function resolveAccount(array $payload): ?IntegrationAccount
    {
        $id = (int) ($payload['account_id'] ?? 0);

        if ($id > 0) {
            return IntegrationAccount::query()
                ->where('provider', 'whatsapp')
                ->where('is_active', true)
                ->whereKey($id)
                ->first();
        }

        return IntegrationAccount::query()
            ->where('provider', 'whatsapp')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}