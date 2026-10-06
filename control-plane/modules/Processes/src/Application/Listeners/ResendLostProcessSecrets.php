<?php

namespace Falak\Processes\Application\Listeners;

use Falak\Fleet\Events\AgentSecretsMissing;
use Falak\Processes\Application\ServerConverger;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Cache;

/**
 * The agent keeps programs' and cron jobs' secret variables on the tmpfs: after a reboot they wait for them. When a
 * heartbeat reports a site of this server, its full proc.apply / cron.apply is sent again (forced: the payloads are
 * unchanged), at most once per server every THROTTLE seconds.
 */
final class ResendLostProcessSecrets
{
    public const THROTTLE = 120;

    public function __construct(
        private readonly ServerConverger $converger,
        private readonly SiteDirectory $sites,
    ) {}

    public function handle(AgentSecretsMissing $event): void
    {
        $slugs = array_map(fn ($site) => $site->slug, $this->sites->forServer($event->serverId));

        if (array_intersect($event->sites, $slugs) === []) {
            return;
        }

        if (Cache::add("processes:resend-secrets:{$event->serverId}", true, self::THROTTLE)) {
            $this->converger->converge($event->serverId, force: true);
        }
    }
}
