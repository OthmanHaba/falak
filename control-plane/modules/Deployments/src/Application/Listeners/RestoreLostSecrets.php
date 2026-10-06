<?php

namespace Falak\Deployments\Application\Listeners;

use Falak\Deployments\Application\Orchestration\StepPayloads;
use Falak\Deployments\Contracts\LiveReleases;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Events\AgentSecretsMissing;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Secrets live on the servers' tmpfs only, so a reboot loses them: the agent reports the sites (heartbeat
 * `missing_secrets`) and this sends what their live release needs again (site.env.write). Heartbeats repeat the report
 * until the files are back, so each server and site gets at most one command per THROTTLE seconds.
 */
final class RestoreLostSecrets
{
    public const THROTTLE = 120;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly LiveReleases $releases,
        private readonly StepPayloads $payloads,
        private readonly AgentGateway $agents,
    ) {}

    public function handle(AgentSecretsMissing $event): void
    {
        $sites = [];

        foreach ($this->sites->forServer($event->serverId) as $site) {
            $sites[$site->slug] = $site;
        }

        $live = $this->releases->onServer($event->serverId);

        foreach ($event->sites as $slug) {
            $site = $sites[$slug] ?? null;
            $release = $site !== null ? ($live[$site->id] ?? null) : null;

            // A running deployment writes the site's secrets itself (and may be replacing the release): ask again later.
            if ($release === null || Deployment::query()->where('site_id', $site->id)->whereIn('status', [DeploymentStatus::Queued, ...DeploymentStatus::occupying()])->exists()) {
                continue;
            }

            if (! Cache::add("deployments:restore-secrets:{$event->serverId}:{$site->id}", true, self::THROTTLE)) {
                continue;
            }

            try {
                // The secrets mode the live release was deployed with (its containers mount the files it chose).
                $mode = (string) (Deployment::query()->find($release->deploymentId)?->setting('secrets_mode') ?? SiteSettings::SECRETS_ENV);
                $payload = $this->payloads->restoreSecrets($site, $release, $mode);

                if ($payload !== null) {
                    $this->agents->dispatch($event->serverId, 'site.env.write', $payload, 120);
                }
            } catch (Throwable $e) {
                Log::warning('Could not restore the secrets of a site after its server lost them.', ['site_id' => $site->id, 'server_id' => $event->serverId, 'error' => $e->getMessage()]);
            }
        }
    }
}
