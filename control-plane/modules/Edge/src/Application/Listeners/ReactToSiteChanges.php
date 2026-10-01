<?php

namespace Kiln\Edge\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Edge\Application\CertificateInstaller;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Application\Jobs\SyncCloudflareDns;
use Kiln\Edge\Application\PathMounts;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Domain\Models\Header;
use Kiln\Edge\Domain\Models\LoadBalancer;
use Kiln\Edge\Domain\Models\Redirect;
use Kiln\Edge\Domain\Models\SecurityRule;
use Kiln\Edge\Domain\Models\SiteSetting;
use Kiln\Edge\Domain\Models\Upstream;
use Kiln\Edge\Events\DomainRemoved;
use Kiln\Processes\Events\OctaneRoutingChanged;
use Kiln\Sites\Events\SiteCreated;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetsChanged;
use Kiln\Sites\Events\SiteUpdated;

/**
 * Re-applies the edge of every affected server when a site changes.
 */
final class ReactToSiteChanges implements ShouldQueue
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly EdgeRoutes $routes,
        private readonly CertificateInstaller $certificates,
        private readonly PathMounts $mounts,
    ) {}

    public function created(SiteCreated $event): void
    {
        $this->changes->siteChanged($event->siteId, $event->serverIds);
        SyncCloudflareDns::site($event->siteId); // compose public services may come with domains
    }

    public function updated(SiteUpdated $event): void
    {
        $this->changes->siteChanged($event->siteId, $event->serverIds);
        SyncCloudflareDns::site($event->siteId); // a no-op for names already in place
        $this->mounts->functionChanged($event->siteId);
    }

    /** Octane became reachable (proxy to it) or is being switched off (serve directly again) on one server. */
    public function octaneRoutingChanged(OctaneRoutingChanged $event): void
    {
        $this->routes->schedule($event->serverId);
    }

    public function targetsChanged(SiteTargetsChanged $event): void
    {
        Upstream::query()->where('site_id', $event->siteId)->whereIn('server_id', $event->removed)->delete();
        SyncCloudflareDns::site($event->siteId); // one record per server the site runs on

        foreach (Certificate::query()->where('site_id', $event->siteId)->get() as $certificate) {
            $this->certificates->sync($certificate);
        }

        $this->changes->siteChanged($event->siteId, [...$event->serverIds, ...$event->removed]);
        // Sites with a path served by this function route to it locally or over HTTPS depending on its servers.
        $this->mounts->functionChanged($event->siteId);
    }

    public function deleted(SiteDeleted $event): void
    {
        SyncCloudflareDns::forgetSite($event->siteId);
        $this->mounts->siteDeleted($event->siteId);

        $balancer = LoadBalancer::query()->where('site_id', $event->siteId)->value('server_id');

        foreach (Certificate::query()->where('site_id', $event->siteId)->where('organization_id', $event->organizationId)->get() as $certificate) {
            $this->certificates->uninstallEverywhere($certificate);
            $certificate->delete();
        }

        foreach (Domain::query()->where('site_id', $event->siteId)->get() as $domain) {
            $domain->delete();
            DomainRemoved::dispatch($domain->id, $domain->site_id, $domain->organization_id, $domain->name);
        }

        foreach ([Redirect::class, SecurityRule::class, Header::class, SiteSetting::class, LoadBalancer::class, Upstream::class] as $model) {
            $model::query()->where('site_id', $event->siteId)->delete();
        }

        $this->routes->schedule(...array_values(array_filter([...$event->serverIds, $balancer])));
    }
}
