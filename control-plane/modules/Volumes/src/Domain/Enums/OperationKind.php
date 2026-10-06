<?php

namespace Falak\Volumes\Domain\Enums;

enum OperationKind: string
{
    case Create = 'create';
    case Resize = 'resize';
    case Delete = 'delete';
    /** Into a new volume: volume.clone on the same server, archive → restore across servers. */
    case Clone = 'clone';
    /** A backup into a new volume. */
    case Restore = 'restore';
    /** archive → restore on the target server → re-attach → redeploy → delete the source. */
    case Move = 'move';
    /** A file or folder of the volume uploaded for the user (volume.download). */
    case Download = 'download';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
