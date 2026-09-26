<?php

namespace Kiln\Sites\Contracts;

/**
 * Deploy script vocabulary (ARCHITECTURE §5). Scripts are bash; Deployments expands the macros into
 * the matching deploy.* steps and exports the variables before running each section.
 */
final class DeployScript
{
    /** @var array<string, string> macro => what Deployments runs in its place */
    public const MACROS = [
        'KILN_FETCH' => 'Download and unpack the built release into $KILN_RELEASE_DIR and link shared paths',
        'KILN_ACTIVATE' => 'Atomically point current/ at the new release (barrier across all servers)',
        'KILN_RESTART_PROCS' => 'Restart queue workers, Horizon, daemons and FrankenPHP workers for the site',
    ];

    /** @var array<string, string> variable => description */
    public const VARIABLES = [
        'KILN_SITE' => 'Site slug',
        'KILN_SITE_ID' => 'Site id',
        'KILN_SITE_ROOT' => 'Site root, e.g. /srv/kiln/sites/shop',
        'KILN_SHARED_DIR' => 'Shared directory (.env, storage, shared paths)',
        'KILN_CURRENT_DIR' => 'The current symlink',
        'KILN_RELEASE_DIR' => 'Directory of the release being deployed',
        'KILN_RELEASE_ID' => 'Release id',
        'KILN_DEPLOYMENT_ID' => 'Deployment id',
        'KILN_TRIGGER' => 'What started the deployment: push, manual, api, rollback',
        'KILN_COMMIT' => 'Commit SHA',
        'KILN_COMMIT_AUTHOR' => 'Commit author',
        'KILN_COMMIT_MESSAGE' => 'Commit message (first line)',
        'KILN_BRANCH' => 'Branch',
        'KILN_REPOSITORY' => 'Repository',
        'KILN_PHP' => 'PHP CLI binary for the site PHP version',
        'KILN_PHP_VERSION' => 'Site PHP version',
        'KILN_NODE_VERSION' => 'Site Node version',
        'KILN_SERVER_ID' => 'Server the script runs on',
        'KILN_ROLE' => 'leader or member',
        'KILN_IS_LEADER' => '1 on the leader (run migrations there), 0 elsewhere',
        'KILN_WEB_DIR' => 'Web directory relative to the release',
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
