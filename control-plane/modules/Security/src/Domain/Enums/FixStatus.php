<?php

namespace Falak\Security\Domain\Enums;

enum FixStatus: string
{
    /** Waiting for the fix before it in its "Fix all safe" batch. */
    case Queued = 'queued';
    case Applying = 'applying';
    case Applied = 'applied';
    /** Nothing to do on the server (already fixed). */
    case Unchanged = 'unchanged';
    case Failed = 'failed';
    case Undoing = 'undoing';
    case Undone = 'undone';

    public function inFlight(): bool
    {
        return in_array($this, [self::Queued, self::Applying, self::Undoing], true);
    }
}
