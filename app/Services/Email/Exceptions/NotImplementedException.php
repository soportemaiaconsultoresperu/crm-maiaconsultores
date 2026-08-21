<?php

declare(strict_types=1);

namespace App\Services\Email\Exceptions;

use RuntimeException;

/**
 * B13 Pasada B — Stub sentinel for not-yet-implemented email providers.
 *
 * Used by the GmailProvider / OutlookProvider stubs to signal that the
 * upstream OAuth credentials are pending (A6 / A7). The error envelope
 * returned from provider::send() carries the FQCN of this class as
 * `error_class`, mirroring the B12 pattern in
 * {@see \App\Services\Automation\Exceptions\NotImplementedException}.
 */
class NotImplementedException extends RuntimeException
{
}
