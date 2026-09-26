<?php

namespace Kiln\Sites\Infrastructure;

use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\Telemetry\Contracts\Data\SiteTelemetryTarget;
use Kiln\Telemetry\Contracts\ServerSites;

/**
 * Sites hosted on a server for telemetry.configure (slug → site id mapping on the agent, which labels
 * logs, spans and cron heartbeats with it). Application logs arrive over OTLP, so no log files are tailed.
 */
final class EloquentServerSites implements ServerSites
{
    public function forServer(string $serverId): array
    {
        return Site::query()
            ->whereIn('id', SiteTarget::query()->select('site_id')->where('server_id', strtolower($serverId))->where('status', '!=', TargetStatus::Removing->value))
            ->orderBy('slug')
            ->get(['id', 'slug'])
            ->map(fn (Site $site) => new SiteTelemetryTarget($site->id, $site->slug))
            ->values()
            ->all();
    }
}
