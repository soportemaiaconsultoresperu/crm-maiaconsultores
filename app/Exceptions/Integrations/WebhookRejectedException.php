<?php

declare(strict_types=1);

namespace App\Exceptions\Integrations;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown by the webhook verification middleware when an inbound payload
 * fails signature, timestamp window, size or format validation.
 *
 * The exception carries an HTTP status so the framework renders the
 * proper 400 response without an explicit abort().
 */
class WebhookRejectedException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        string $message = 'Webhook rejected.',
        private readonly int $statusCode = 400,
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}