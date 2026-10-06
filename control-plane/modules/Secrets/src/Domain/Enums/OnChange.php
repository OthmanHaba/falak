<?php

namespace Falak\Secrets\Domain\Enums;

/**
 * What a watched linked secret does when its value changes upstream (after recording a new version).
 */
enum OnChange: string
{
    case None = 'none';

    /** Restart the processes of the services that use it. */
    case Restart = 'restart';

    /** Redeploy the services that use it (the new value is written into their environment). */
    case Redeploy = 'redeploy';
}
