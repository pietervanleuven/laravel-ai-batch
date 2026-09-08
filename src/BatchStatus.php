<?php

namespace AiBatch;

enum BatchStatus: string
{
    case Validating = 'validating';
    case InProgress = 'in_progress';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';

    /**
     * Determine if the batch will not change state anymore.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Expired, self::Cancelled], true);
    }
}
