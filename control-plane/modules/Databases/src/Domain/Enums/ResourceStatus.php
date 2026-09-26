<?php

namespace Kiln\Databases\Domain\Enums;

/**
 * Convergence state of a database or database user on its server.
 */
enum ResourceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Deleting = 'deleting';
}
