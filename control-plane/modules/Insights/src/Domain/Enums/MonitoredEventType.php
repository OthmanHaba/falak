<?php

namespace Kiln\Insights\Domain\Enums;

/**
 * Event types (contracts/telemetry `kiln.event.type`) that thresholds can watch.
 */
enum MonitoredEventType: string
{
    case Request = 'request';
    case Job = 'job';
    case Query = 'query';
    case Command = 'command';
    case ScheduledTask = 'scheduled_task';
    case OutgoingRequest = 'outgoing_request';

    public function label(): string
    {
        return match ($this) {
            self::Request => 'Routes',
            self::Job => 'Jobs',
            self::Query => 'Queries',
            self::Command => 'Commands',
            self::ScheduledTask => 'Scheduled tasks',
            self::OutgoingRequest => 'Outgoing requests',
        };
    }

    public function noun(): string
    {
        return match ($this) {
            self::Request => 'route',
            self::Job => 'job',
            self::Query => 'query',
            self::Command => 'command',
            self::ScheduledTask => 'scheduled task',
            self::OutgoingRequest => 'outgoing request',
        };
    }
}
