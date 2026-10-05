<?php

namespace Falak\Sites\Contracts;

/**
 * Deploy script vocabulary (ARCHITECTURE §5). Scripts are bash; Deployments expands the macros into
 * the matching deploy.* steps and exports the variables before running each section.
 */
final class DeployScript
{
    /** @var array<string, string> macro => what Deployments runs in its place */
    public const MACROS = [
        'FALAK_FETCH' => 'Download and unpack the built release into $FALAK_RELEASE_DIR and link shared paths',
        'FALAK_ACTIVATE' => 'Atomically point current/ at the new release (barrier across all servers)',
        'FALAK_RESTART_PROCS' => 'Restart queue workers, Horizon, daemons and FrankenPHP workers for the site',
    ];

    /** @var array<string, string> variable => description */
    public const VARIABLES = [
        'FALAK_SITE' => 'Site slug',
        'FALAK_SITE_ID' => 'Site id',
        'FALAK_SITE_ROOT' => 'Site root, e.g. /srv/falak/sites/shop',
        'FALAK_SHARED_DIR' => 'Shared directory (.env, storage, shared paths)',
        'FALAK_CURRENT_DIR' => 'The current symlink',
        'FALAK_RELEASE_DIR' => 'Directory of the release being deployed',
        'FALAK_RELEASE_ID' => 'Release id',
        'FALAK_DEPLOYMENT_ID' => 'Deployment id',
        'FALAK_TRIGGER' => 'What started the deployment: push, manual, api, rollback',
        'FALAK_COMMIT' => 'Commit SHA',
        'FALAK_COMMIT_AUTHOR' => 'Commit author',
        'FALAK_COMMIT_MESSAGE' => 'Commit message (first line)',
        'FALAK_BRANCH' => 'Branch',
        'FALAK_REPOSITORY' => 'Repository',
        'FALAK_PHP' => 'PHP CLI binary for the site PHP version',
        'FALAK_PHP_VERSION' => 'Site PHP version',
        'FALAK_NODE_VERSION' => 'Site Node version',
        'FALAK_SERVER_ID' => 'Server the script runs on',
        'FALAK_ROLE' => 'leader or member',
        'FALAK_IS_LEADER' => '1 on the leader (run migrations there), 0 elsewhere',
        'FALAK_WEB_DIR' => 'Web directory relative to the release',
    ];

    /**
     * Macros the script references.
     *
     * @return list<string>
     */
    public static function macrosIn(string $script): array
    {
        return array_values(array_filter(array_keys(self::MACROS), fn (string $macro) => preg_match('/\$\{?'.$macro.'\b/', $script) === 1));
    }
}
