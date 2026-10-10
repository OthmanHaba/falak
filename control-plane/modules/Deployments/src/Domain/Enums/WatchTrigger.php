<?php

namespace Falak\Deployments\Domain\Enums;

/**
 * What can trip a release's watch window.
 */
enum WatchTrigger: string
{
    /** The health check through the edge failed N times in a row. */
    case Health = 'health';
    /** A process of the site was OOM-killed or keeps restarting (Limits). */
    case Crash = 'crash';
    /** The release's 5xx rate in the edge access log. */
    case Errors = 'errors';
    /** A new error issue in Insights. */
    case Issue = 'issue';

    public function label(): string
    {
        return match ($this) {
            self::Health => 'Health check',
            self::Crash => 'Crash or OOM kill',
            self::Errors => '5xx rate',
            self::Issue => 'New error',
        };
    }
}
