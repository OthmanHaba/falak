<?php

namespace Kiln\Fleet\Contracts;

enum AgentUpgradeStatus: string
{
    /** Waiting for its turn in a rollout. */
    case Queued = 'queued';
    /** system.upgrade_agent sent; waiting for the agent to come back with the new build. */
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** A rollout stopped after another server's upgrade failed. */
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
