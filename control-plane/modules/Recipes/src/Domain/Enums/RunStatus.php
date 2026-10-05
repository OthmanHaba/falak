<?php

namespace Falak\Recipes\Domain\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Partial = 'partial';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Partial], true);
    }
}
