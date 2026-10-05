<?php

namespace Falak\Alerting\Domain\Enums;

enum AlertOutcome: string
{
    /** Routed to at least one rule (channels and/or in-app). */
    case Delivered = 'delivered';
    /** An unresolved alert with the same dedup key was already delivered. */
    case Deduplicated = 'deduplicated';
    /** Every matching rule was inside its quiet hours (in-app notifications still sent). */
    case QuietHours = 'quiet_hours';
    /** Every matching rule hit its hourly rate limit. */
    case RateLimited = 'rate_limited';
    /** No enabled rule matched the type and severity. */
    case NoRoute = 'no_route';
    /** A recovery for a key that was never alerted. */
    case RecoverySkipped = 'recovery_skipped';
}
