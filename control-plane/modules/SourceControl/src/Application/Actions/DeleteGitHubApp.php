<?php

namespace Falak\SourceControl\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\GitHubApp;

/**
 * Forget a registered GitHub App: uninstall it from every account (best effort), disconnect its connections and
 * delete the stored credentials. The app registration itself can only be deleted on GitHub.
 */
final class DeleteGitHubApp
{
    public function __construct(
        private readonly DeleteConnection $deleteConnection,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(GitHubApp $app, bool $cleanUpProvider = true): void
    {
        Connection::query()
            ->where('github_app_id', $app->id)
            ->each(fn (Connection $connection) => ($this->deleteConnection)($connection, $cleanUpProvider));

        $app->delete();

        $this->audit->record('source_control.github_app_deleted', 'source_control_github_app', $app->id, [
            'app_id' => $app->app_id,
            'slug' => $app->slug,
        ], $app->organization_id);
    }
}
