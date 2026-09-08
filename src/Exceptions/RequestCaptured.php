<?php

namespace AiBatch\Exceptions;

use Laravel\Ai\Exceptions\FailoverableException;
use RuntimeException;

/**
 * Thrown by the capturing gateway to unwind the SDK's generation loop once the request body is known.
 *
 * It is failoverable and the prompt is marked as a non-final attempt, so the SDK does not
 * dispatch AgentFailed for it.
 *
 * @internal
 */
class RequestCaptured extends RuntimeException implements FailoverableException
{
    //
}
