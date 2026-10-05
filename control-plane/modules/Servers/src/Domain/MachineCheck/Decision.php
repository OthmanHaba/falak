<?php

namespace Falak\Servers\Domain\MachineCheck;

/**
 * What provisioning does with one component of the machine (docs/plans/MACHINE_CHECK.md).
 */
enum Decision: string
{
    /** Nothing there: Falak installs / configures it. */
    case Install = 'install';
    /** A compatible, working one is there: used as is, its packages are never installed. */
    case Adopt = 'adopt';
    /** Partly there: only the missing pieces are installed, from the same source. */
    case Complete = 'complete';
    /** A conflict Falak won't resolve automatically: nothing is applied until it is fixed. */
    case Block = 'block';
    /** Not part of the server's stack; only reported because something was found. */
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Install => 'Install',
            self::Adopt => 'Use existing',
            self::Complete => 'Install missing parts',
            self::Block => 'Blocked',
            self::Skip => 'Not managed',
        };
    }
}
