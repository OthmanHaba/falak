<?php

namespace Falak\SourceControl\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\SourceControl\Application\Actions\DeleteConnection;
use Falak\SourceControl\Application\Actions\DeleteGitHubApp;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\GitHubApp;
use Falak\SourceControl\Domain\Models\Push;

/**
 * Tenant cleanup: registered GitHub Apps, connections, deploy keys, webhooks and the push log (provider clean-up is
 * best effort).
 */
final class DeleteOrganizationConnections implements ShouldQueue
{
    public function __construct(
        private readonly DeleteConnection $delete,
        private readonly DeleteGitHubApp $deleteApp,
    ) {}

    public function handle(OrganizationDeleted $event): void
    {
        GitHubApp::query()->where('organization_id', $event->organizationId)->each(fn (GitHubApp $app) => ($this->deleteApp)($app));
        Connection::query()->where('organization_id', $event->organizationId)->each(fn (Connection $connection) => ($this->delete)($connection));
        Push::query()->where('organization_id', $event->organizationId)->delete();
    }
}
