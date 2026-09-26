<?php

namespace Kiln\Sites\Infrastructure;

use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;

/**
 * The static KILN_* variables of a site on one server (deploy scripts and site commands).
 */
final class SiteVariables
{
    /**
     * @return array<string, string>
     */
    public static function for(Site $site, string $serverId): array
    {
        /** @var ?SiteTarget $target */
        $target = $site->targets->firstWhere('server_id', $serverId);
        $leader = $target?->isLeader() ?? false;

        return array_filter([
            'KILN_SITE' => $site->slug,
            'KILN_SITE_ID' => $site->id,
            'KILN_SITE_ROOT' => $site->rootPath(),
            'KILN_SHARED_DIR' => $site->rootPath().'/shared',
            'KILN_CURRENT_DIR' => $site->currentPath(),
            'KILN_BRANCH' => (string) $site->branch,
            'KILN_REPOSITORY' => (string) $site->repository,
            'KILN_PHP' => $site->runtime->isPhp() ? $site->phpBinary() : '',
            'KILN_PHP_VERSION' => (string) $site->php_version,
            'KILN_NODE_VERSION' => (string) $site->node_version,
            'KILN_SERVER_ID' => $serverId,
            'KILN_ROLE' => $leader ? 'leader' : 'member',
            'KILN_IS_LEADER' => $leader ? '1' : '0',
            'KILN_WEB_DIR' => $site->web_directory,
        ], fn (string $value) => $value !== '');
    }
}
