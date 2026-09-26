<?php

namespace Kiln\Fleet\Contracts;

enum CommandStatus: string
{
    case Queued = 'queued';
    case Delivered = 'delivered';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::TimedOut, self::Cancelled], true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * @return list<self>
     */
    public static function pending(): array
    {
        return [self::Queued, self::Delivered, self::Running];
    }
}
