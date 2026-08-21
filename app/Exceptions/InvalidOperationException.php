<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain invariant violation: the operation is invalid for the current
 * state of the aggregate (e.g. editing an opportunity that is already
 * won/lost). Distinct from InvalidArgumentException (bad input) and from
 * authorization failures (policies own those).
 */
class InvalidOperationException extends RuntimeException
{
}
