<?php

namespace Falak\Volumes\Domain\Enums;

enum BackupStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Removed from storage by retention; the row stays as history. */
    case Pruned = 'pruned';
}
