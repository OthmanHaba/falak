<?php

namespace Falak\Edge\Domain\Enums;

enum ApplyStatus: string
{
    /** edge.caddy.apply dispatched, waiting for the agent. */
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';
    /** Could not be dispatched (no agent, invalid payload). */
    case Error = 'error';
}
