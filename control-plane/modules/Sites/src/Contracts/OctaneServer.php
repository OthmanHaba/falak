<?php

namespace Falak\Sites\Contracts;

/**
 * The Laravel Octane server a site runs (`php artisan octane:start --server=…`).
 *
 *  - FrankenPHP: worker mode through the frankenphp binary Falak installs for FrankenPHP servers
 *    (/usr/local/bin/frankenphp, found on PATH by Octane). Only on the FrankenPHP runtime.
 *  - Swoole: needs the swoole (or openswoole) PHP extension for the site's PHP version.
 *  - RoadRunner: needs the `rr` binary on PATH or in the project root and `spiral/roadrunner-cli` +
 *    `spiral/roadrunner-http` in the app.
 */
enum OctaneServer: string
{
    case FrankenPhp = 'frankenphp';
    case Swoole = 'swoole';
    case RoadRunner = 'roadrunner';

    public function label(): string
    {
        return match ($this) {
            self::FrankenPhp => 'FrankenPHP',
            self::Swoole => 'Swoole',
            self::RoadRunner => 'RoadRunner',
        };
    }

    public static function defaultFor(SiteRuntime $runtime): self
    {
        return $runtime === SiteRuntime::FrankenPhp ? self::FrankenPhp : self::Swoole;
    }

    /** FrankenPHP's Octane server needs the frankenphp binary, which only FrankenPHP servers have. */
    public function supports(SiteRuntime $runtime): bool
    {
        return $runtime->isPhp() && ($this !== self::FrankenPhp || $runtime === SiteRuntime::FrankenPhp);
    }

    /**
     * @return list<self>
     */
    public static function for(SiteRuntime $runtime): array
    {
        return array_values(array_filter(self::cases(), fn (self $server) => $server->supports($runtime)));
    }
}
