<?php

namespace Kiln\Edge\Infrastructure;

use Illuminate\Support\Collection;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Enums\InstallStatus;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Edge\Domain\Models\CertificateInstall;
use Kiln\Edge\Domain\Models\CloudflareTunnel;
use Kiln\Edge\Domain\Models\CloudflareZone;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Domain\Models\Header;
use Kiln\Edge\Domain\Models\LoadBalancer;
use Kiln\Edge\Domain\Models\Redirect;
use Kiln\Edge\Domain\Models\SecurityRule;
use Kiln\Edge\Domain\Models\SiteSetting;
use Kiln\Edge\Domain\Models\Upstream;
use Kiln\Edge\Infrastructure\Dns\CloudflareRanges;
use Kiln\Fleet\Contracts\AgentUpgrades;
use Kiln\Processes\Contracts\OctaneRouting;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;

/**
 * Compiles the full edge.caddy.apply payload for one server from every site routed through it:
 *
 *  - sites targeting the server ("direct"; "backend" when a load balancer fronts the site — then served
 *    as plain HTTP on :80 for the LB, which enforces TLS, IP and auth rules),
 *  - sites load-balanced by the server ("lb": reverse_proxy to the targets).
 *
 * Domains of one site are grouped by TLS configuration; the first group keeps the site's stable route id
 * (see {@see routeId()}), further groups get "<routeId>-<n>". Output is deterministic.
 *
 * Laravel Octane: a PHP site with Octane on is served by `reverse_proxy 127.0.0.1:<octane port>` with `root` set
 * (the agent serves existing non-PHP files under the document root directly and proxies everything else) —
 * but only once Processes verified Octane answers on that port ({@see OctaneRouting}); until then (never
 * deployed / placeholder release, still starting, broken) the site keeps being served by FrankenPHP /
 * PHP-FPM directly. Domains, test domain, TLS, redirects, headers, auth and LB backends are unchanged.
 */
final class RouteCompiler
{
    /** @var Collection<int, CloudflareZone> managed Cloudflare zones of the server being compiled (see compile()) */
    private Collection $zones;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
        private readonly OctaneRouting $octane,
        private readonly ?string $acmeEmail = null,
        private readonly ?string $acmeCa = null,
        private readonly string $testDomainTls = 'acme',
    ) {}

    /**
     * edge.caddy.apply gained trusted_proxies and tls.http_challenge_only in 0.3.0; agents reject unknown fields, so
     * older releases never get them (they apply the rest and pick them up after an agent update). Development builds
     * and unknown versions do.
     */
    private static function agentKnowsCloudflare(string $serverId): bool
    {
        $version = app(AgentUpgrades::class)->versionsFor([$serverId])[$serverId]->version ?? null;

        // Release versions only ("v0.2.8", "0.3.0-rc.1" counts as 0.3.0); dev / CI builds are current.
        if ($version === null || preg_match('/^v?(\d+\.\d+\.\d+)(?:-[0-9A-Za-z.]+)?$/', $version, $m) !== 1) {
            return true;
        }

        return version_compare($m[1], '0.3.0', '>=');
    }

    /**
     * ACME for a host: in a managed Cloudflare zone, HTTP-01 only (TLS-ALPN-01 cannot pass through the proxy, and
     * each failed attempt counts against Let's Encrypt's limit of 5 per hour).
     *
     * @return array{mode: string, http_challenge_only?: bool}
     */
    private function acme(string $host): array
    {
        return $this->zones->contains(fn (CloudflareZone $zone) => $zone->covers($host))
            ? ['mode' => 'acme', 'http_challenge_only' => true]
            : ['mode' => 'acme'];
    }

    public static function routeId(string $siteId): string
    {
        return strtolower($siteId);
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(string $serverId): array
    {
        $entries = [];
        $organizationId = $this->servers->find($serverId)?->organizationId;
        $this->zones = $organizationId !== null && self::agentKnowsCloudflare($serverId)
            ? CloudflareZone::query()->where('organization_id', $organizationId)->get()
            : collect();

        foreach ($this->routedSites($serverId) as [$site, $role, $balancer]) {
            array_push($entries, ...$this->siteEntries($site, $serverId, $role, $balancer));
        }

        usort($entries, fn (array $a, array $b) => strcmp($a['id'], $b['id']));

        // Behind Cloudflare the connection comes from Cloudflare: trust its ranges for the visitor's IP
        // (CF-Connecting-IP), so logs, IP allow / deny lists and rate limits see the visitor.
        $proxied = $this->zones->isNotEmpty();
        // Through a tunnel, cloudflared connects to Caddy from the server itself.
        $local = $proxied && CloudflareTunnel::query()->where('server_id', strtolower($serverId))->exists() ? ['127.0.0.1/32', '::1/128'] : [];

        return array_filter([
            'acme_email' => $this->acmeEmail ?: null,
            'acme_ca' => $this->acmeCa ?: null,
            'trusted_proxies' => $proxied ? [...CloudflareRanges::RANGES, ...$local] : null,
        ]) + ['sites' => $entries];
    }

    /**
     * Server ids whose compiled config depends on the site (its targets and its load balancer).
     *
     * @return list<string>
     */
    public function serversForSite(string $siteId): array
    {
        $site = $this->sites->find($siteId);
        $ids = $site ? $site->serverIds() : [];

        $lb = LoadBalancer::query()->where('site_id', $siteId)->value('server_id');

        if (is_string($lb)) {
            $ids[] = $lb;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{0: SiteData, 1: 'direct'|'backend'|'lb', 2: ?LoadBalancer}>
     */
    private function routedSites(string $serverId): array
    {
        $routed = [];
        $balancers = LoadBalancer::query()->get()->keyBy('site_id');

        foreach (LoadBalancer::query()->where('server_id', $serverId)->get() as $balancer) {
            $site = $this->sites->find($balancer->site_id);

            if ($site && $site->organizationId === $balancer->organization_id) {
                $routed[$site->id] = [$site, 'lb', $balancer];
            }
        }

        foreach ($this->sites->forServer($serverId) as $site) {
            if (! isset($routed[$site->id])) {
                $balancer = $balancers->get($site->id);
                $routed[$site->id] = [$site, $balancer ? 'backend' : 'direct', $balancer];
            }
        }

        ksort($routed);

        return array_values($routed);
    }

    /**
     * @param  'direct'|'backend'|'lb'  $role
     * @return list<array<string, mixed>>
     */
    private function siteEntries(SiteData $site, string $serverId, string $role, ?LoadBalancer $balancer): array
    {
        $base = $role === 'lb' ? $this->balancerHandler($site, $balancer) : $this->siteHandler($site, $serverId);

        if ($base === null) {
            return [];
        }

        $groups = $this->domainGroups($site, $serverId, $role);
        $rules = $this->rules($site->id, $role);

        // Per-site HTTP access log (shipped to Loki as the site's kind=access records). Backends behind a load
        // balancer only see the balancer's requests; the balancer logs them with the real client.
        if ($role !== 'backend') {
            $rules['access_log'] = $site->slug;
        }
        $routeId = self::routeId($site->id);
        $entries = $role === 'direct' ? $this->composeServiceEntries($site, $routeId, $rules) : [];
        $n = 0;

        foreach ($groups as $group) {
            $entry = ['id' => $n === 0 ? $routeId : "{$routeId}-{$n}", 'domains' => $group['domains']];

            if ($group['redirect_domains'] !== []) {
                $entry['redirect_domains'] = $group['redirect_domains'];
            }

            $entry['tls'] = $group['tls'];
            $entries[] = $entry + $base + $rules;
            $n++;
        }

        return $entries;
    }

    /**
     * Docker Compose: every public service after the first gets its own route — its custom domain and/or
     * <service>-<slug>.<test domain> — proxied to 127.0.0.1:<host port>. (The first service is the site's
     * primary route: site domains + <slug> test domain → app port; its custom domain is added here.)
     *
     * @param  array<string, mixed>  $rules
     * @return list<array<string, mixed>>
     */
    private function composeServiceEntries(SiteData $site, string $routeId, array $rules): array
    {
        if ($site->runtime !== SiteRuntime::Compose || $site->compose === null) {
            return [];
        }

        $entries = [];
        $testTls = ['mode' => $this->testDomainTls === 'internal' ? 'internal' : 'acme'];

        foreach ($site->compose->publicServices as $i => $public) {
            if ($public->hostPort === null) {
                continue;
            }

            $label = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($public->service)), '-') ?: 'svc';
            $handler = ['kind' => 'reverse_proxy', 'upstreams' => [['dial' => "127.0.0.1:{$public->hostPort}"]]];

            if ($public->domain !== null) {
                $entries[] = ['id' => "{$routeId}-svc-{$label}", 'domains' => [$public->domain], 'tls' => $this->acme($public->domain)] + $handler + $rules;
            }

            if ($i > 0 && $public->testDomain !== null) {
                $entries[] = ['id' => "{$routeId}-svc-{$label}-test", 'domains' => [strtolower($public->testDomain)], 'tls' => $testTls] + $handler + $rules;
            }
        }

        return $entries;
    }

    /**
     * Serving block for the site's runtime on one of its targets.
     *
     * @return array<string, mixed>|null
     */
    private function siteHandler(SiteData $site, string $serverId): ?array
    {
        $proxy = function (?string $dial) use ($site): ?array {
            if ($dial === null) {
                return null;
            }

            return array_filter([
                'kind' => 'reverse_proxy',
                'upstreams' => [['dial' => $dial]],
                // Compose services have container healthchecks (`up --wait`); Caddy's active check only accepts 2xx
                // and would take apps that redirect `/` to a login page out of rotation.
                'health_uri' => $site->runtime === SiteRuntime::Compose ? null : $this->path($site->healthCheckPath),
            ], fn ($v) => $v !== null);
        };

        $local = $site->appPort ? "127.0.0.1:{$site->appPort}" : null;

        if ($site->runtime->isPhp() && ($octane = $this->octaneHandler($site, $serverId)) !== null) {
            return $octane;
        }

        return match ($site->runtime) {
            SiteRuntime::FrankenPhp => ['kind' => 'frankenphp', 'root' => $site->documentRoot()],
            SiteRuntime::PhpFpm => $site->fpmSocket() ? ['kind' => 'php_fpm', 'root' => $site->documentRoot(), 'php_fpm_socket' => $site->fpmSocket()] : null,
            SiteRuntime::Static => ['kind' => 'static', 'root' => $site->documentRoot()],
            SiteRuntime::Node, SiteRuntime::Bun, SiteRuntime::Deno => $proxy($local),
            SiteRuntime::Docker, SiteRuntime::Compose => $proxy(
                Upstream::query()->where('site_id', $site->id)->where('server_id', $serverId)->value('upstream') ?? $local,
            ),
            // The server's function gateway starts the function's instances on demand; no active health check, which
            // would keep it from scaling to zero.
            SiteRuntime::Function => [
                'kind' => 'reverse_proxy',
                'upstreams' => [['dial' => (string) config('edge.function_gateway', '127.0.0.1:7070')]],
                'request_headers' => ['X-Kiln-Function' => $site->slug],
            ],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function octaneHandler(SiteData $site, string $serverId): ?array
    {
        if (! $site->framework->isLaravel() || ! $site->laravel->servesOctane()) {
            return null;
        }

        $port = $this->octane->listeningPort($site->id, $serverId);

        if ($port === null || $port !== $site->laravel->octanePort) {
            return null;
        }

        return [
            'kind' => 'reverse_proxy',
            'root' => $site->documentRoot(),
            'upstreams' => [['dial' => "127.0.0.1:{$port}"]],
            // Deploys restart Octane (new release): the edge holds requests until it listens again instead of failing them.
            'try_duration_s' => max(1, (int) config('edge.octane_try_duration_seconds', 30)),
        ];
    }

    /**
     * Ids of the sites a compiled payload reverse-proxies to Octane (PHP site routes with a root are Octane routes).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function octaneSites(array $payload): array
    {
        $ids = [];

        foreach ((array) ($payload['sites'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['kind'] ?? null) === 'reverse_proxy' && isset($entry['root'], $entry['try_duration_s'])) {
                $ids[] = substr((string) $entry['id'], 0, 26);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function balancerHandler(SiteData $site, ?LoadBalancer $balancer): ?array
    {
        if ($balancer === null) {
            return null;
        }

        $upstreams = [];

        foreach ($site->targets as $target) {
            $server = $this->servers->find($target->serverId);
            $ip = $server?->privateIpv4 ?: $server?->ipv4;

            if ($ip === null) {
                continue;
            }

            $dial = (str_contains($ip, ':') ? "[{$ip}]" : $ip).':'.$balancer->backend_port;

            // The schema has no weights: an upstream listed N times gets N shares of the rotation.
            for ($i = 0; $i < $balancer->weightFor($target->serverId); $i++) {
                $upstreams[] = ['dial' => $dial];
            }
        }

        if ($upstreams === []) {
            return null;
        }

        return array_filter([
            'kind' => 'reverse_proxy',
            'upstreams' => $upstreams,
            'lb_policy' => $balancer->policy->value,
            'health_uri' => $this->path($balancer->health_uri),
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  'direct'|'backend'|'lb'  $role
     * @return list<array{domains: list<string>, redirect_domains: list<string>, tls: array<string, mixed>}>
     */
    private function domainGroups(SiteData $site, string $serverId, string $role): array
    {
        $domains = Domain::query()
            ->with('dnsCredential')
            ->where('site_id', $site->id)
            ->where('organization_id', $site->organizationId)
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        $installed = CertificateInstall::query()
            ->where('server_id', $serverId)
            ->where('status', InstallStatus::Installed)
            ->pluck('certificate_id')
            ->all();

        $groups = [];
        $seen = [];

        $add = function (string $key, array $tls, string $host, ?string $redirect) use (&$groups, &$seen) {
            if (isset($seen[$host]) || ($redirect !== null && isset($seen[$redirect]))) {
                return;
            }

            $groups[$key] ??= ['domains' => [], 'redirect_domains' => [], 'tls' => $tls];
            $groups[$key]['domains'][] = $host;
            $seen[$host] = true;

            if ($redirect !== null) {
                $groups[$key]['redirect_domains'][] = $redirect;
                $seen[$redirect] = true;
            }
        };

        foreach ($domains as $domain) {
            $tls = $role === 'backend' ? ['mode' => 'off'] : $this->tls($domain, $installed);

            if ($tls === null) {
                continue;
            }

            $add(json_encode($tls, JSON_THROW_ON_ERROR), $tls, $domain->servedHost(), $domain->redirectHost());
        }

        if ($site->testDomain !== null) {
            $tls = ['mode' => $role === 'backend' ? 'off' : ($this->testDomainTls === 'internal' ? 'internal' : 'acme')];
            $add(json_encode($tls, JSON_THROW_ON_ERROR), $tls, strtolower($site->testDomain), null);
        }

        return array_values($groups);
    }

    /**
     * @param  list<string>  $installedCertificates  certificate ids installed on the server
     * @return array<string, mixed>|null null when the domain cannot be served on this server yet
     */
    private function tls(Domain $domain, array $installedCertificates): ?array
    {
        return match ($domain->tls_mode) {
            TlsMode::Auto => $domain->isWildcard() ? null : $this->acme($domain->name),
            TlsMode::Internal => ['mode' => 'internal'],
            TlsMode::Off => ['mode' => 'off'],
            TlsMode::Custom => $domain->certificate_id !== null && in_array($domain->certificate_id, $installedCertificates, true)
                ? ['mode' => 'custom', 'cert_name' => $this->certName($domain->certificate_id)]
                : null,
            TlsMode::Dns => $domain->dnsCredential
                ? ['mode' => 'acme', 'dns' => ['provider' => $domain->dnsCredential->provider, 'api_token' => $domain->dnsCredential->api_token]]
                : null,
        };
    }

    private function certName(string $certificateId): string
    {
        return (string) Certificate::query()->whereKey($certificateId)->value('name');
    }

    /**
     * Redirects, headers, auth and site settings.
     *
     * @param  'direct'|'backend'|'lb'  $role
     * @return array<string, mixed>
     */
    private function rules(string $siteId, string $role): array
    {
        $rules = [];
        $settings = SiteSetting::for($siteId);
        // Behind a load balancer the LB enforces redirects, auth and IP rules (backends only see the LB's IP).
        $edge = $role !== 'backend';

        $headers = Header::query()->where('site_id', $siteId)->orderBy('name')->pluck('value', 'name')->all();

        if ($headers !== []) {
            $rules['headers'] = $headers;
        }

        if ($edge) {
            $auth = SecurityRule::query()->where('site_id', $siteId)->orderByRaw('path is not null')->orderBy('path')->orderBy('username')->get()
                ->map(fn (SecurityRule $rule) => array_filter([
                    'username' => $rule->username,
                    'password_hash' => $rule->password_hash,
                    'path' => $rule->path,
                ], fn ($v) => $v !== null))
                ->values()
                ->all();

            if ($auth !== []) {
                $rules['basic_auth'] = $auth;
            }

            $redirects = Redirect::query()->where('site_id', $siteId)->orderBy('position')->orderBy('id')->get()
                ->map(fn (Redirect $redirect) => ['from' => $redirect->from, 'to' => $redirect->to, 'status' => $redirect->status])
                ->values()
                ->all();

            if ($redirects !== []) {
                $rules['redirects'] = $redirects;
            }

            if ($settings->deny_ips !== []) {
                $rules['deny_ips'] = array_values($settings->deny_ips);
            }

            if ($settings->allow_ips !== []) {
                $rules['allow_ips'] = array_values($settings->allow_ips);
            }
        }

        if ($settings->max_body_bytes) {
            $rules['max_body_bytes'] = $settings->max_body_bytes;
        }

        if (! $settings->encode) {
            $rules['encode'] = false;
        }

        return $rules;
    }

    private function path(?string $path): ?string
    {
        return $path !== null && str_starts_with($path, '/') ? $path : null;
    }
}
