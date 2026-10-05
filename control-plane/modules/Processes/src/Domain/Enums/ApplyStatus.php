<?php

namespace Falak\Processes\Domain\Enums;

/**
 * State of the last proc.apply / cron.apply sent to a server.
 */
enum ApplyStatus: string
{
    /** Dispatched, waiting for the agent. */
    case Pending = 'pending';
    case Applied = 'applied';
    /** The agent reported a failure. */
    case Failed = 'failed';
    /** Could not be dispatched (agent offline, invalid payload). */
    case Error = 'error';
}
