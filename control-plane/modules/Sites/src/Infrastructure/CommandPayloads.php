<?php

namespace Falak\Sites\Infrastructure;

use Falak\Servers\Contracts\Data\PhpSettings;
use Falak\Sites\Domain\Models\Site;

/**
 * Agent command payloads built by Sites (validated against contracts/agent-protocol by the gateway).
 */
final class CommandPayloads
{
    /**
     * @return array<string, mixed> system.user.create
     */
    public static function user(Site $site): array
    {
        return [
            'name' => $site->unix_user,
            'home' => $site->rootPath(),
            'shell' => '/bin/bash',
            'isolated' => true,
        ];
    }

    /**
     * @return array<string, mixed> runtime.fpm.pool
     */
    public static function fpmPool(Site $site, string $phpVersion, ?PhpSettings $settings, string $state = 'present'): array
    {
        if ($state === 'absent') {
            return ['php_version' => $phpVersion, 'pool' => $site->slug, 'user' => $site->unix_user, 'state' => 'absent'];
        }

        $fpm = $settings->fpm ?? ['pm' => 'dynamic', 'max_children' => 5, 'start_servers' => 2, 'min_spare_servers' => 1, 'max_spare_servers' => 3, 'max_requests' => 500];

        $payload = [
            'php_version' => $phpVersion,
            'pool' => $site->slug,
            'user' => $site->unix_user,
            'group' => $site->unix_user,
            'listen' => "/run/php/falak-{$site->slug}-{$phpVersion}.sock",
            'pm' => $fpm['pm'],
            'max_children' => max(1, (int) $fpm['max_children']),
            'start_servers' => max(1, (int) $fpm['start_servers']),
            'min_spare_servers' => max(1, (int) $fpm['min_spare_servers']),
            'max_spare_servers' => max(1, (int) $fpm['max_spare_servers']),
            'max_requests' => max(0, (int) $fpm['max_requests']),
            'state' => 'present',
        ];

        if ($site->isolated) {
            // Keep PHP inside the site's own tree.
            $payload['php_admin_values'] = ['open_basedir' => $site->rootPath().'/:/tmp/:/usr/share/php/'];
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, mixed> system.exec
     */
    public static function exec(Site $site, string $command, array $env = []): array
    {
        $current = escapeshellarg($site->currentPath());

        $payload = [
            'script' => "set -e\nif [ ! -d {$current} ]; then echo 'The site has not been deployed yet.' >&2; exit 1; fi\ncd {$current}\n{$command}\n",
            'shell' => '/bin/bash',
            'user' => $site->unix_user,
        ];

        if ($env !== []) {
            $payload['env'] = $env;
        }

        return $payload;
    }
}
