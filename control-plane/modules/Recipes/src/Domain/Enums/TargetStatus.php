<?php

namespace Kiln\Recipes\Domain\Enums;

enum TargetStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unavailable = 'unavailable';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Unavailable], true);
    }
}
