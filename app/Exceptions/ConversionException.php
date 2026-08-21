<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a lead cannot be converted to a customer (ADR-001):
 * already converted (final status or an existing customer references it),
 * or the lead is otherwise in a non-convertible state.
 */
class ConversionException extends RuntimeException
{
}
