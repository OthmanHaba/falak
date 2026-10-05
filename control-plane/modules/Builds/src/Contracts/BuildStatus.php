<?php

namespace Falak\Builds\Contracts;

enum BuildStatus: string
{
    case Queued = 'queued';
    /** Handed to a builder that has not reported `started` yet. */
    case Assigned = 'assigned';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::TimedOut], true);
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Queued, self::Assigned, self::Running];
    }
}
