<?php

namespace Falak\Deployments\Domain\Enums;

/**
 * The watch window after a release went live.
 */
enum WatchStatus: string
{
    case Watching = 'watching';
    /** The window ended without a trigger. */
    case Passed = 'passed';
    /** A trigger fired and the site was rolled back to the previous release. */
    case RolledBack = 'rolled_back';
    /** A trigger fired and only an alert went out (alert-only, or the loop guard held the rollback back). */
    case Alerted = 'alerted';
    /** Another deployment of the site started, or the site went away. */
    case Stopped = 'stopped';
}
