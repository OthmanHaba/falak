<?php

namespace Falak\Databases\Domain\Enums;

enum RestoreStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** A point-in-time restore made its new instance: swap, keep or discard it. */
    case AwaitingDecision = 'awaiting_decision';

    /** A point-in-time restore whose new instance was thrown away. */
    case Discarded = 'discarded';
}
