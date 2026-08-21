<?php

declare(strict_types=1);

namespace App\Services\Automation\Exceptions;

use RuntimeException;

/**
 * Thrown by action stubs whose real implementation is not yet wired.
 *
 * The RunAutomationAction Job catches every Throwable, but a dedicated
 * exception class lets the listener / engine recognise "not implemented"
 * distinctly from real failures (e.g. for the admin notification text).
 */
class NotImplementedException extends RuntimeException
{
}