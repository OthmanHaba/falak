<?php

namespace Falak\Databases\Domain\Enums;

/**
 * Lifecycle of a database container.
 */
enum InstanceStatus: string
{
    /** db.instance.create is running (pull, first start). */
    case Pending = 'pending';

    case Active = 'active';

    case Failed = 'failed';

    /** A major upgrade copies its data into a new instance. */
    case Upgrading = 'upgrading';

    /** Replaced by a major upgrade: stopped, deleted at retire_at. */
    case Retired = 'retired';

    case Deleting = 'deleting';

    /** A point-in-time restore's new instance: read-only, waiting for a decision (swap, keep, discard). */
    case Inspecting = 'inspecting';
}
