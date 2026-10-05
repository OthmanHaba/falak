<?php

namespace Falak\Insights\Contracts;

enum IssueKind: string
{
    case Exception = 'exception';
    case Performance = 'performance';
    case Heartbeat = 'heartbeat';

    public function label(): string
    {
        return match ($this) {
            self::Exception => 'Exception',
            self::Performance => 'Performance',
            self::Heartbeat => 'Scheduled task',
        };
    }
}
