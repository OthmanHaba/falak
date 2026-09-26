<?php

namespace Kiln\Sites\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\SiteUpdated;
use Kiln\SourceControl\Events\ConnectionDeleted;

/**
 * Sites keep their repository name when the connection goes away but lose the link, key and push-to-deploy.
 */
final class DetachSourceConnection implements ShouldQueue
{
    public function handle(ConnectionDeleted $event): void
    {
        Site::query()->with('targets')->where('source_connection_id', $event->connectionId)->get()->each(function (Site $site) {
            $site->forceFill(['source_connection_id' => null, 'deploy_key_id' => null, 'push_to_deploy' => false])->save();
            SiteUpdated::dispatch($site->id, $site->organization_id, ['source_connection_id', 'deploy_key_id', 'push_to_deploy'], $site->serverIds());
        });
    }
}
