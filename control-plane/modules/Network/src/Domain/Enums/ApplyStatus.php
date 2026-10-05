<?php

namespace Falak\Network\Domain\Enums;

/**
 * Convergence state of a desired-state command (firewall ruleset, WireGuard interface).
 */
enum ApplyStatus: string
{
    /** Desired state recorded but not dispatched (server not active yet, key not installed). */
    case Pending = 'pending';
    case Applying = 'applying';
    case Applied = 'applied';
    case Failed = 'failed';
}
