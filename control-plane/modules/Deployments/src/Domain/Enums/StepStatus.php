<?php

namespace Falak\Deployments\Domain\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    /** Command queued for the agent / build requested / health check scheduled. */
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Not needed (e.g. empty script section) or abandoned after a failure. */
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Skipped], true);
    }

    /** Dependents may proceed. */
    public function isSatisfied(): bool
    {
        return $this === self::Succeeded || $this === self::Skipped;
    }
}
