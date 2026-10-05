<?php

namespace Falak\Deployments\Domain\Enums;

enum ReleaseStatus: string
{
    case Pending = 'pending';
    /** The site's current release. */
    case Active = 'active';
    /** Retained on the servers; can be rolled back to. */
    case Inactive = 'inactive';
    case Failed = 'failed';
    /** Deleted from the servers by retention. */
    case Pruned = 'pruned';
}
