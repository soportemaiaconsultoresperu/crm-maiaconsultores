<?php

declare(strict_types=1);

namespace App\Services\Automation\Exceptions;

use RuntimeException;

/**
 * Thrown by the WebhookAction when the configured destination is not in
 * the allow-list of `integrations.webhooks.allowed_destinations`.
 *
 * The RunAutomationAction Job catches every Throwable; this dedicated
 * exception class lets the engine record a distinct error_class for the
 * admin notification and the per-step error message.
 */
class WebhookNotAuthorizedException extends RuntimeException
{
}