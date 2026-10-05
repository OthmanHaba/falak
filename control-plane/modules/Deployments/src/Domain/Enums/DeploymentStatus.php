<?php

namespace Falak\Deployments\Domain\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    /** Claimed from the queue but held until the site's servers finish preparing (nothing planned yet). */
    case Waiting = 'waiting';
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

    /**
     * Statuses that hold the site's queue: at most one deployment per site is in one of them.
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return [self::Waiting, self::Building, self::Deploying];
    }
}
