<?php

namespace Falak\Volumes\Contracts;

/**
 * What a volume is attached to.
 */
enum AttachableType: string
{
    /** A site: a docker site's container, or a classic site's shared path. */
    case Site = 'site';

    /** A service of a compose site (the attachment names the site and the service). */
    case ComposeService = 'compose_service';

    /** A database container's data directory (database containers, step 3). */
    case Database = 'database';
}
