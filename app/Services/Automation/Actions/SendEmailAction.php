<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationExecutionStep;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Send an email through Laravel's mail layer (Mail::raw).
 *
 * B12 first-step wrapper (D-24): uses Mail::raw + Mail::queue for
 * asynchronous dispatch. The EmailProvider abstraction (B11) is consulted
 * later in B13 for full SMTP provider selection.
 *
 * Payload:
 *  - to (string|array, required)
 *  - subject (string, required)
 *  - body (string, required)
 *  - queue (bool, optional — default true)
 */
class SendEmailAction implements ActionContract
{
    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $to = $payload['to'] ?? null;
        $subject = (string) ($payload['subject'] ?? '');
        $body = (string) ($payload['body'] ?? '');
        $useQueue = (bool) ($payload['queue'] ?? true);

        if ($to === null || $subject === '' || $body === '') {
            throw new InvalidArgumentException('SendEmailAction: to, subject and body are required.');
        }

        $recipients = is_array($to) ? array_values(array_filter($to)) : [$to];

        foreach ($recipients as $recipient) {
            if ($useQueue) {
                Mail::queue([], [], function ($message) use ($recipient, $subject, $body): void {
                    $message->to($recipient)->subject($subject);
                    $message->setBody($body, 'text/plain');
                });
            } else {
                Mail::raw($body, function ($message) use ($recipient, $subject): void {
                    $message->to($recipient)->subject($subject);
                });
            }
        }

        Log::info('SendEmailAction dispatched', [
            'step_id' => $step->id,
            'recipients' => $recipients,
            'subject' => $subject,
            'queued' => $useQueue,
        ]);

        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'recipients' => $recipients,
            'subject' => $subject,
            'queued' => $useQueue,
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        return [
            'would_send_email' => true,
            'payload' => $payload,
        ];
    }
}