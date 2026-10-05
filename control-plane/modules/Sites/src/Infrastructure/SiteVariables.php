<?php

namespace Falak\Sites\Infrastructure;

use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;

/**
 * The static FALAK_* variables of a site on one server (deploy scripts and site commands).
 * Ids cross the agent boundary as upper-case ULIDs, matching telemetry resource attributes.
 */
final class SiteVariables
{
    /**
     * Upper-case ULID values of FALAK_*_ID variables (e.g. FALAK_DEPLOYMENT_ID supplied by Deployments).
     *
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public static function normalizeIds(array $variables): array
    {
        foreach ($variables as $key => $value) {
            if (preg_match('/^FALAK_[A-Z_]*ID$/', $key) === 1 && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) === 1) {
                $variables[$key] = strtoupper($value);
            }
        }

        return $variables;
    }

    /**
     * @return array<string, string>
     */
    public static function for(Site $site, string $serverId): array
    {
        /** @var ?SiteTarget $target */
        $target = $site->targets->firstWhere('server_id', $serverId);
        $leader = $target?->isLeader() ?? false;

        return array_filter([
            'FALAK_SITE' => $site->slug,
            'FALAK_SITE_ID' => strtoupper($site->id),
            'FALAK_SITE_ROOT' => $site->rootPath(),
            'FALAK_SHARED_DIR' => $site->rootPath().'/shared',
            'FALAK_CURRENT_DIR' => $site->currentPath(),
            'FALAK_BRANCH' => (string) $site->branch,
            'FALAK_REPOSITORY' => (string) $site->repository,
            'FALAK_PHP' => $site->runtime->isPhp() ? $site->phpBinary() : '',
            'FALAK_PHP_VERSION' => (string) $site->php_version,
            'FALAK_NODE_VERSION' => (string) $site->node_version,
            'FALAK_SERVER_ID' => strtoupper($serverId),
            'FALAK_ROLE' => $leader ? 'leader' : 'member',
            'FALAK_IS_LEADER' => $leader ? '1' : '0',
            'FALAK_WEB_DIR' => $site->web_directory,
        ], fn (string $value) => $value !== '');
    }
}
