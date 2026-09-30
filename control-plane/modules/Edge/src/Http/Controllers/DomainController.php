<?php

namespace Kiln\Edge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kiln\Edge\Application\Actions\AddDomain;
use Kiln\Edge\Application\Actions\MakePrimaryDomain;
use Kiln\Edge\Application\Actions\RemoveDomain;
use Kiln\Edge\Application\Actions\UpdateDomain;
use Kiln\Edge\Application\CloudflareEdgeControls;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Application\Jobs\SyncCloudflareDns;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Enums\LbPolicy;
use Kiln\Edge\Domain\Enums\WwwRedirect;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Edge\Domain\Models\CertificateInstall;
use Kiln\Edge\Domain\Models\CloudflareZone;
use Kiln\Edge\Domain\Models\DnsCredential;
use Kiln\Edge\Domain\Models\DnsRecord;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Domain\Models\LoadBalancer;
use Kiln\Edge\Domain\Models\ServerState;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareError;
use Kiln\Edge\Infrastructure\EloquentSiteDomains;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Sites\Contracts\Data\DomainChoice;
use Kiln\Sites\Contracts\DomainType;
use Kiln\Sites\Contracts\TargetRole;

final class DomainController extends Controller
{
    use ResolvesSite;

    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    /** JSON for the Settings tab's Networking section (domains, TLS, certificates, edge servers, load balancer). */
    public function index(Request $request, string $site, EdgeChanges $changes): JsonResponse|RedirectResponse
    {
        $siteData = $this->site($request, $site);

        if (! $this->wantsPanelJson($request)) {
            return $this->toNetworking($siteData);
        }

        $serverIds = $changes->serversFor($siteData->id);
        $servers = collect($serverIds)->map(fn (string $id) => $this->servers->find($id))->filter()->keyBy('id');
        $states = ServerState::query()->whereIn('server_id', $serverIds)->get()->keyBy('server_id');
        $balancer = LoadBalancer::query()->where('site_id', $siteData->id)->first();
        $roles = collect($siteData->targets)->mapWithKeys(fn ($target) => [$target->serverId => $target->role->value]);

        return response()->json(['data' => [
            'testDomain' => $siteData->testDomain,
            'slug' => $siteData->slug,
            'domains' => Domain::query()->where('site_id', $siteData->id)->orderByDesc('is_primary')->orderBy('name')->get()->map(fn (Domain $domain) => [
                'id' => $domain->id,
                'name' => $domain->name,
                'is_primary' => $domain->is_primary,
                'www_redirect' => $domain->www_redirect->value,
                'tls_mode' => $domain->tls_mode->value,
                'certificate_id' => $domain->certificate_id,
                'dns_credential_id' => $domain->dns_credential_id,
                'hosts' => $domain->hosts(),
                'served_host' => $domain->servedHost(),
                'supports_www' => $domain->supportsWwwRedirect(),
                'wildcard' => $domain->isWildcard(),
                // In a Cloudflare zone Kiln manages: records are created for it, no DNS instructions needed.
                'cloudflare' => ($zone = CloudflareZone::forHost($siteData->organizationId, $domain->name)) !== null ? [
                    'zone' => $zone->name,
                    'proxied' => $domain->cloudflare_proxied ?? $zone->proxied,
                    'override' => $domain->cloudflare_proxied,
                    'cache' => $domain->cloudflare_cache ?? 'standard',
                    'records' => DnsRecord::query()->where('domain_id', $domain->id)->orderBy('name')->get()
                        ->map(fn (DnsRecord $r) => ['name' => $r->name, 'type' => $r->type, 'content' => $r->content, 'status' => $r->status, 'error' => $r->error])->values(),
                ] : null,
            ])->values(),
            'certificates' => Certificate::query()->with('installs')->where('site_id', $siteData->id)->latest()->get()->map(fn (Certificate $certificate) => [
                'id' => $certificate->id,
                'domains' => $certificate->domains,
                'issuer' => $certificate->issuer,
                'not_after' => $certificate->not_after?->toIso8601String(),
                'expired' => $certificate->isExpired(),
                'fingerprint' => $certificate->fingerprint,
                'created_at' => $certificate->created_at->toIso8601String(),
                'installs' => $certificate->installs->map(fn (CertificateInstall $install) => [
                    'server_id' => $install->server_id,
                    'server_name' => $servers->get($install->server_id)?->name ?? $install->server_id,
                    'status' => $install->status->value,
                    'error' => $install->error,
                ])->values(),
            ])->values(),
            'dnsCredentials' => DnsCredential::query()->where('organization_id', $siteData->organizationId)->orderBy('name')->get(['id', 'name', 'provider']),
            'dnsProviders' => collect(DnsCredential::PROVIDERS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'tlsModes' => collect(TlsMode::cases())->map(fn (TlsMode $mode) => ['value' => $mode->value, 'label' => $mode->label()]),
            'edgeServers' => $servers->map(fn (ServerData $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'role' => $balancer?->server_id === $server->id ? 'load balancer' : ($roles[$server->id] ?? 'target'),
                'serves_http' => $server->type->servesHttp(),
                'state' => ($state = $states->get($server->id)) ? [
                    'status' => $state->status->value,
                    'error' => $state->error,
                    'command_id' => $state->command_id,
                    'dispatched_at' => $state->dispatched_at?->toIso8601String(),
                    'applied_at' => $state->applied_at?->toIso8601String(),
                    'routes' => $state->routes,
                ] : null,
            ])->values(),
            'loadBalancer' => $balancer ? [
                'server_id' => $balancer->server_id,
                'policy' => $balancer->policy->value,
                'health_uri' => $balancer->health_uri,
                'backend_port' => $balancer->backend_port,
                'weights' => (object) $balancer->weights,
            ] : null,
            'lbServers' => collect($this->servers->forOrganization($siteData->organizationId, [ServerType::LoadBalancer]))
                ->map(fn (ServerData $server) => ['id' => $server->id, 'name' => $server->name, 'status' => $server->status->value])->values(),
            'targets' => collect($siteData->targets)->map(fn ($target) => [
                'server_id' => $target->serverId,
                'name' => $servers->get($target->serverId)?->name ?? $this->servers->find($target->serverId)?->name ?? $target->serverId,
                'role' => $target->role->value,
            ])->values(),
            'policies' => collect(LbPolicy::cases())->map(fn (LbPolicy $policy) => ['value' => $policy->value, 'label' => $policy->label()]),
            'routeId' => app(EdgeRoutes::class)->routeId($siteData->id),
            'can' => [
                'manage' => $this->access->can($request->user(), $siteData->organizationId, 'edge.manage'),
                'manage_dns' => $this->access->can($request->user(), $siteData->organizationId, 'edge.dns.manage'),
            ],
        ]]);
    }

    public function store(Request $request, string $site, AddDomain $add, EloquentSiteDomains $domains): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');

        // {type: generated}: `<slug>.<ip-with-dashes>.<suffix>` for the leader (or the load balancer).
        if ($request->input('type') === DomainType::Generated->value) {
            $targets = $siteData->targets;
            usort($targets, fn ($a, $b) => ($b->role === TargetRole::Leader) <=> ($a->role === TargetRole::Leader));
            $request->merge(['name' => $domains->resolveChoice(
                $siteData->organizationId,
                new DomainChoice(DomainType::Generated),
                $siteData->slug,
                array_map(fn ($target) => $target->serverId, $targets),
                'name',
                $siteData->id,
            )]);
        }

        $data = $this->validated($request, withName: true);

        $add($siteData, $data['name'], TlsMode::from($data['tls_mode'] ?? 'auto'), WwwRedirect::from($data['www_redirect'] ?? 'none'), $data['certificate_id'] ?? null, $data['dns_credential_id'] ?? null);

        return back();
    }

    public function update(Request $request, string $site, string $domain, UpdateDomain $update): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $model = $this->domain($siteData->id, $domain);
        $data = $this->validated($request, withName: false);

        $update($model, TlsMode::from($data['tls_mode']), WwwRedirect::from($data['www_redirect'] ?? 'none'), $data['certificate_id'] ?? null, $data['dns_credential_id'] ?? null);

        return back();
    }

    /** Orange / grey cloud for one domain (null: the zone's default). */
    public function cloudflare(Request $request, string $site, string $domain, AuditLog $audit): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $model = $this->domain($siteData->id, $domain);
        $data = $request->validate(['proxied' => ['present', 'nullable', 'boolean']]);

        if (CloudflareZone::forHost($siteData->organizationId, $model->name) === null) {
            throw ValidationException::withMessages(['proxied' => 'This domain is not in a Cloudflare zone Kiln manages.']);
        }

        $model->forceFill(['cloudflare_proxied' => $data['proxied']])->save();
        SyncCloudflareDns::domain($model->id);
        $audit->record('edge.domain_cloudflare_proxy', 'site', $siteData->id, ['domain' => $model->name, 'proxied' => $data['proxied']], $siteData->organizationId);

        return back();
    }

    /** Cloudflare cache mode of one domain: standard | everything | bypass. */
    public function cloudflareCache(Request $request, string $site, string $domain, CloudflareEdgeControls $controls): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $data = $request->validate(['mode' => ['required', Rule::in(CloudflareEdgeControls::CACHE_MODES)]]);

        try {
            $controls->setCacheMode($this->domain($siteData->id, $domain), $data['mode']);
        } catch (CloudflareError $e) {
            throw ValidationException::withMessages(['mode' => $e->getMessage().' (the token needs Zone → Cache Rules → Edit)']);
        }

        return back();
    }

    /** Purge every name of the site at Cloudflare. */
    public function cloudflarePurge(Request $request, string $site, CloudflareEdgeControls $controls): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $purged = $controls->purgeSite($siteData->id);

        return back()->with($purged === [] ? 'error' : 'success', $purged === [] ? 'Nothing purged: the site has no names in a Cloudflare zone Kiln manages.' : 'Purged '.implode(', ', $purged).'.');
    }

    public function primary(Request $request, string $site, string $domain, MakePrimaryDomain $makePrimary): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $makePrimary($this->domain($siteData->id, $domain));

        return back();
    }

    public function destroy(Request $request, string $site, string $domain, RemoveDomain $remove): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $remove($this->domain($siteData->id, $domain));

        return back();
    }

    /**
     * Force a fresh edge.caddy.apply on one of the servers routing the site.
     */
    public function apply(Request $request, string $site, EdgeChanges $changes, EdgeRoutes $routes): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $serverId = (string) $request->validate(['server_id' => ['required', 'string', Rule::in($changes->serversFor($siteData->id))]])['server_id'];

        $routes->apply($serverId, force: true);

        return back();
    }

    private function domain(string $siteId, string $domainId): Domain
    {
        return Domain::query()->where('site_id', $siteId)->findOrFail($domainId);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $withName): array
    {
        return $request->validate([
            ...($withName ? ['name' => ['required', 'string', 'max:253']] : []),
            'tls_mode' => [$withName ? 'nullable' : 'required', Rule::enum(TlsMode::class)],
            'www_redirect' => ['nullable', Rule::enum(WwwRedirect::class)],
            'certificate_id' => ['nullable', 'string', 'max:26'],
            'dns_credential_id' => ['nullable', 'string', 'max:26'],
        ]);
    }
}
