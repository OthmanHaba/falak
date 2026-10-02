<?php

namespace Kiln\Edge;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Kiln\Alerting\Contracts\AlertTypes;
use Kiln\Alerting\Contracts\Severity;
use Kiln\Deployments\Events\DeploymentRolledBack;
use Kiln\Deployments\Events\DeploymentSucceeded;
use Kiln\Edge\Application\CertificateInstaller;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Application\Jobs\PurgeCloudflareCache;
use Kiln\Edge\Application\Jobs\ReconcileCloudflareTunnels;
use Kiln\Edge\Application\Jobs\SyncCloudflareDns;
use Kiln\Edge\Application\Listeners\ForgetDeletedOrganization;
use Kiln\Edge\Application\Listeners\ForgetDeletedServer;
use Kiln\Edge\Application\Listeners\HandleEdgeCommandOutcome;
use Kiln\Edge\Application\Listeners\ReactToSiteChanges;
use Kiln\Edge\Application\Listeners\ReapplyAfterAgentUpgrade;
use Kiln\Edge\Application\PathMounts;
use Kiln\Edge\Contracts\DnsCheck;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Events\CertificateInstallFailed;
use Kiln\Edge\Events\CertificateIssued;
use Kiln\Edge\Events\DomainAdded;
use Kiln\Edge\Events\DomainRemoved;
use Kiln\Edge\Infrastructure\CloudflareOriginPolicy;
use Kiln\Edge\Infrastructure\Dns\DnsResolver;
use Kiln\Edge\Infrastructure\Dns\DohResolver;
use Kiln\Edge\Infrastructure\Dns\StreamTlsProbe;
use Kiln\Edge\Infrastructure\Dns\SystemResolver;
use Kiln\Edge\Infrastructure\Dns\TlsProbe;
use Kiln\Edge\Infrastructure\EloquentEdgeRoutes;
use Kiln\Edge\Infrastructure\EloquentSiteDomains;
use Kiln\Edge\Infrastructure\ResolverDnsCheck;
use Kiln\Edge\Infrastructure\RouteCompiler;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Events\AgentVersionChanged;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Network\Contracts\WebOriginPolicy;
use Kiln\Processes\Contracts\OctaneRouting;
use Kiln\Processes\Events\OctaneRoutingChanged;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteDomains;
use Kiln\Sites\Events\ComposeServiceExtracted;
use Kiln\Sites\Events\ComposeServicesUnpublished;
use Kiln\Sites\Events\SiteCreated;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetsChanged;
use Kiln\Sites\Events\SiteUpdated;

class EdgeServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     * Edge registers after Sites, so its SiteDomains binding replaces Sites' null implementation.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        // Origin lock-down of servers behind Cloudflare (Network compiles it into the firewall).
        WebOriginPolicy::class => CloudflareOriginPolicy::class,
        SiteDomains::class => EloquentSiteDomains::class,
        DnsCheck::class => ResolverDnsCheck::class,
        TlsProbe::class => StreamTlsProbe::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/edge.php', 'edge');

        $this->app->bind(RouteCompiler::class, fn ($app) => new RouteCompiler(
            $app->make(SiteDirectory::class),
            $app->make(ServerDirectory::class),
            $app->make(OctaneRouting::class),
            config('edge.acme_email') ?: null,
            config('edge.acme_ca') ?: null,
            (string) config('edge.test_domain_tls', 'acme'),
        ));

        $this->app->bind(EdgeRoutes::class, fn ($app) => new EloquentEdgeRoutes(
            $app->make(RouteCompiler::class),
            $app->make(AgentGateway::class),
            $app->make(ServerDirectory::class),
            (int) config('edge.apply_delay_seconds', 2),
            (int) config('edge.apply_timeout_seconds', 120),
            (string) config('edge.test_domain_tls', 'acme'),
        ));

        $this->app->singleton(DnsResolver::class, fn ($app) => config('edge.dns.resolver') === 'system'
            ? new SystemResolver
            : new DohResolver($app->make(HttpFactory::class), (string) config('edge.dns.doh_url'), (int) config('edge.dns.timeout_seconds', 3)));

        $this->app->bind(CertificateInstaller::class, fn ($app) => new CertificateInstaller(
            $app->make(AgentGateway::class),
            $app->make(EdgeChanges::class),
            (int) config('edge.cert_install_timeout_seconds', 60),
        ));
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('edge.view', [Role::Admin, Role::Developer, Role::Viewer], 'View domains, certificates and routing rules', 'edge');
        $registry->register('edge.manage', [Role::Admin, Role::Developer], 'Manage domains, certificates, redirects, security rules and load balancers', 'edge');
        $registry->register('edge.dns.manage', [Role::Admin], 'Manage DNS provider credentials for DNS-01 certificates and generated domains', 'edge');

        $types = $this->app->make(AlertTypes::class);
        $types->register(CertificateInstallFailed::ALERT_TYPE, 'Certificate install failed', 'Edge', Severity::Critical);
        $types->register(CertificateIssued::ALERT_TYPE, 'Certificate installed', 'Edge', Severity::Info);

        Event::listen(SiteCreated::class, [ReactToSiteChanges::class, 'created']);
        Event::listen(SiteUpdated::class, [ReactToSiteChanges::class, 'updated']);
        Event::listen(SiteTargetsChanged::class, [ReactToSiteChanges::class, 'targetsChanged']);
        Event::listen(SiteDeleted::class, [ReactToSiteChanges::class, 'deleted']);
        Event::listen(ComposeServiceExtracted::class, [ReactToSiteChanges::class, 'extracted']);
        Event::listen(ComposeServicesUnpublished::class, [ReactToSiteChanges::class, 'unpublished']);
        Event::listen(OctaneRoutingChanged::class, [ReactToSiteChanges::class, 'octaneRoutingChanged']);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        // Cloudflare DNS follows the domains (records Kiln created only).
        Event::listen(DomainAdded::class, fn (DomainAdded $event) => SyncCloudflareDns::domain($event->domainId));
        Event::listen(DomainRemoved::class, fn (DomainRemoved $event) => SyncCloudflareDns::forget($event->domainId));
        // A function's domain is how other servers reach its paths (edge mounts).
        Event::listen(DomainAdded::class, fn (DomainAdded $event) => app(PathMounts::class)->functionChanged($event->siteId));
        Event::listen(DomainRemoved::class, fn (DomainRemoved $event) => app(PathMounts::class)->functionChanged($event->siteId));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new ReconcileCloudflareTunnels)->everyFiveMinutes()->name('edge:cloudflare-tunnels')->withoutOverlapping();
        });
        // Visitors get the new release: purge the site's names at Cloudflare after deploys and rollbacks.
        Event::listen(DeploymentSucceeded::class, fn (DeploymentSucceeded $event) => PurgeCloudflareCache::dispatch($event->siteId));
        Event::listen(DeploymentRolledBack::class, fn (DeploymentRolledBack $event) => PurgeCloudflareCache::dispatch($event->siteId));
        Event::listen(AgentVersionChanged::class, ReapplyAfterAgentUpgrade::class);
        Event::listen(OrganizationDeleted::class, ForgetDeletedOrganization::class);
        Event::listen(CommandFinished::class, [HandleEdgeCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleEdgeCommandOutcome::class, 'handleFailed']);
    }
}
