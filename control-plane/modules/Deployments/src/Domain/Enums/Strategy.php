<?php

namespace Falak\Deployments\Domain\Enums;

use Falak\Sites\Contracts\SiteRuntime;

/**
 * Deployment strategies (ARCHITECTURE §5).
 */
enum Strategy: string
{
    /** Each server activates as soon as it is ready (no cross-server barrier). */
    case InPlace = 'in-place';
    /** Releases + atomic `current` swap, all servers activate together behind a barrier. */
    case ZeroDowntime = 'zero-downtime';
    /** Containers: start the new color next to the old one, health check, swap upstream. */
    case BlueGreen = 'blue-green';
    /** N servers at a time; each batch must pass its health check before the next. */
    case Rolling = 'rolling';
    /** One server first, verify it, then the rest. */
    case Canary = 'canary';
    /** Docker Compose: pull on every server, then `docker compose up --wait` (previous release's files on failure). */
    case Compose = 'compose';

    public function label(): string
    {
        return match ($this) {
            self::InPlace => 'In place',
            self::ZeroDowntime => 'Zero downtime',
            self::BlueGreen => 'Blue / green',
            self::Rolling => 'Rolling',
            self::Canary => 'Canary',
            self::Compose => 'Compose',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::InPlace => 'Every server switches to the new release as soon as it is ready. Fastest; servers may briefly run different releases.',
            self::ZeroDowntime => 'All servers fetch and prepare the release, then switch together (activation barrier).',
            self::BlueGreen => 'The new container starts next to the old one and takes over after passing its health check.',
            self::Rolling => 'Servers switch in batches; each batch must pass its health check before the next starts.',
            self::Canary => 'One server switches first and must pass its health check before the rest follow.',
            self::Compose => 'Every server pulls the new images, then all run `docker compose up --wait`; a failure brings back the previous release’s files.',
        };
    }

    /**
     * @return list<self>
     */
    public static function for(SiteRuntime $runtime): array
    {
        if ($runtime === SiteRuntime::Compose) {
            return [self::Compose, self::Rolling, self::Canary];
        }

        return $runtime->usesDocker()
            ? [self::BlueGreen, self::Rolling, self::Canary]
            : [self::ZeroDowntime, self::InPlace, self::Rolling, self::Canary];
    }

    public static function default(SiteRuntime $runtime): self
    {
        return self::for($runtime)[0];
    }
}
