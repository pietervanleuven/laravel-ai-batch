<?php

namespace AiBatch;

enum BatchRequestFailureType: string
{
    /** The provider processed the request and returned an error. */
    case Errored = 'errored';

    /** The batch was cancelled before the request ran. */
    case Canceled = 'canceled';

    /** The batch expired before the request ran. */
    case Expired = 'expired';

    /** The result line could not be decoded. */
    case Invalid = 'invalid';

    /**
     * Determine if the request never reached the model, so it was not billed.
     */
    public function neverRan(): bool
    {
        return in_array($this, [self::Canceled, self::Expired], true);
    }
}
