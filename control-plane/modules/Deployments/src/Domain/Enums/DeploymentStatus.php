<?php

namespace Kiln\Deployments\Domain\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Deploying = 'deploying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return $this === self::Building || $this === self::Deploying;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Building, self::Deploying];
    }
}
