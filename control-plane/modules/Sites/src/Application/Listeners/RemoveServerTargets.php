<?php

namespace Falak\Sites\Application\Listeners;

use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Events\ServerDeleted;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteTargetsChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

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
