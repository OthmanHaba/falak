<?php

namespace Falak\Volumes\Domain\Enums;

/**
 * What happens to the running containers that mount a volume while the agent reads it (archive, clone).
 */
enum Consistency: string
{
    /** Read while they write: fine for append-only or rarely written data. */
    case None = 'none';
    /** docker pause for the seconds the copy takes. */
    case Pause = 'pause';
    /** Stopped, then started again. */
    case Stop = 'stop';
}
