<?php

namespace Falak\Databases\Contracts;

/**
 * A restore drill's outcome. Skipped: the server had no room for it (no drill server set) or there was nothing to
 * restore.
 */
enum DrillStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function isFinished(): bool
    {
        return $this !== self::Pending;
    }
}
