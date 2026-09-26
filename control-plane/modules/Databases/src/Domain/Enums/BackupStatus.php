<?php

namespace Kiln\Databases\Domain\Enums;

enum BackupStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Pruned = 'pruned';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Pruned], true);
    }
}
