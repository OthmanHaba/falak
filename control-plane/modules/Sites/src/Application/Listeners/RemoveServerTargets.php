<?php

namespace Kiln\Sites\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\Sites\Events\SiteTargetsChanged;

/**
 * A deleted server leaves every site's deployment group; the next target becomes leader when needed.
 */
final class RemoveServerTargets implements ShouldQueue
{
    public function __construct(private readonly AuditLog $audit) {}

    public function handle(ServerDeleted $event): void
    {
        SiteTarget::query()->with('site')->where('server_id', $event->serverId)->get()->each(function (SiteTarget $target) use ($event) {
            $site = $target->site;
            $target->delete();

            $site->load('targets');

            if ($site->targets->isNotEmpty() && ! $site->leaderTarget()) {
                $site->targets->first()?->forceFill(['role' => TargetRole::Leader])->save();
                $site->load('targets');
            }

            $this->audit->record('site.target_removed', 'site', $site->id, ['server_id' => $event->serverId, 'reason' => 'server_deleted'], $site->organization_id);

            /** @var Site $site */
            SiteTargetsChanged::dispatch($site->id, $site->organization_id, [], [$event->serverId], $site->serverIds(), $site->leaderTarget()?->server_id);
        });
    }
}
