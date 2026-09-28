<?php

namespace Kiln\SourceControl\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\SourceControl\Application\Actions\DeleteConnection;
use Kiln\SourceControl\Application\Actions\DeleteGitHubApp;
use Kiln\SourceControl\Domain\Models\Connection;
use Kiln\SourceControl\Domain\Models\GitHubApp;
use Kiln\SourceControl\Domain\Models\Push;

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
