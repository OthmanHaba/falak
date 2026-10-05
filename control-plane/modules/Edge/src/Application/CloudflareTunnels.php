<?php

namespace Falak\Edge\Application;

use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Domain\Models\CloudflareTunnel;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\OriginLock;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareError;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Contracts\Firewalls;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cloudflare Tunnel as a server's ingress: cloudflared on the server connects out to Cloudflare, which routes the
 * server's names through it, so the server needs no open inbound ports (and works behind NAT).
 *
 * Caddy stays in charge: the tunnel sends each name to https://localhost:443 with that name as SNI, and only
 * Let's Encrypt's HTTP-01 path to http://localhost:80, so certificates, routing rules and logs work as before. Names
 * in the connection's zones get a proxied CNAME to <tunnel id>.cfargotunnel.com instead of A records.
 */
final class CloudflareTunnels
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
        private readonly SiteDirectory $sites,
        private readonly EdgeRoutes $routes,
        private readonly AuditLog $audit,
    ) {}

    public function enable(string $serverId, DnsCredential $credential, ?string $userId = null): CloudflareTunnel
    {
        $server = $this->server($serverId, $credential->organization_id);

        if (CloudflareTunnel::query()->where('server_id', $server->id)->exists()) {
            throw ValidationException::withMessages(['server' => "{$server->name} already runs through a tunnel."]);
        }

        if ($credential->account_id === null) {
            throw ValidationException::withMessages(['server' => 'This connection has no Cloudflare account; reconnect it.']);
        }

        $api = CloudflareApi::with($credential->api_token);
        $name = 'falak-'.Str::slug($server->name).'-'.substr($server->id, -6);

        try {
            $tunnelId = $api->createTunnel($credential->account_id, $name);
        } catch (CloudflareError $e) {
            throw ValidationException::withMessages(['server' => $e->getMessage().' (the token needs Account → Cloudflare Tunnel → Edit)']);
        }

        try {
            $token = $api->tunnelToken($credential->account_id, $tunnelId);
            $tunnel = CloudflareTunnel::query()->create([
                'organization_id' => $credential->organization_id,
                'server_id' => $server->id,
                'dns_credential_id' => $credential->id,
                'account_id' => $credential->account_id,
                'tunnel_id' => $tunnelId,
                'name' => $name,
                'token' => $token,
                'status' => CloudflareTunnel::INSTALLING,
            ]);
        } catch (Throwable $e) {
            // Leave no orphan tunnel in the account.
            try {
                $api->deleteTunnel($credential->account_id, $tunnelId);
            } catch (CloudflareError) {
            }

            throw $e instanceof CloudflareError ? ValidationException::withMessages(['server' => $e->getMessage()]) : $e;
        }

        $this->install($tunnel, $server);
        try {
            $this->syncIngress($server->id);
        } catch (CloudflareError) {
            // recorded on the tunnel; the next edge apply retries
        }
        // DNS moves to the tunnel once cloudflared reports it is running (HandleEdgeCommandOutcome).
        $this->routes->schedule($server->id);

        $this->audit->record('edge.cloudflare_tunnel_enabled', 'server', $server->id, ['tunnel' => $name], $credential->organization_id);

        return $tunnel;
    }

    /** Back to public ingress: cloudflared removed, names back to A / AAAA records, the tunnel deleted. */
    public function disable(CloudflareTunnel $tunnel): void
    {
        try {
            $this->agents->dispatch($tunnel->server_id, 'net.tunnel.apply', ['state' => 'absent'], 120, "edge.tunnel.remove:{$tunnel->id}:".Str::ulid());
        } catch (AgentUnavailable) {
            // The server is gone or offline: nothing to stop there.
        }

        $tunnel->delete();
        // Closed web ports only make sense behind the tunnel: open them again.
        OriginLock::query()->where('server_id', $tunnel->server_id)->where('mode', OriginLock::CLOSED)->delete();
        app(Firewalls::class)->converge($tunnel->server_id);
        $this->changed($tunnel->server_id);

        try {
            CloudflareApi::with($tunnel->credential->api_token)->deleteTunnel($tunnel->account_id, $tunnel->tunnel_id);
        } catch (CloudflareError $e) {
            Log::warning('edge: could not delete the Cloudflare tunnel', ['tunnel' => $tunnel->name, 'error' => $e->getMessage()]);
        }

        $this->audit->record('edge.cloudflare_tunnel_disabled', 'server', $tunnel->server_id, ['tunnel' => $tunnel->name], $tunnel->organization_id);
    }

    /** Re-send cloudflared (after an error, or to move to a new pinned release). */
    public function reinstall(CloudflareTunnel $tunnel): void
    {
        $this->install($tunnel, $this->server($tunnel->server_id, $tunnel->organization_id));
    }

    /**
     * The tunnel's routes: every name the server serves that lives in a zone of the tunnel's Cloudflare account.
     * Called after each edge apply of the server (its names may have changed); a no-op without a tunnel.
     *
     * @throws CloudflareError so the apply job retries
     */
    public function syncIngress(string $serverId): void
    {
        $tunnel = CloudflareTunnel::query()->with('credential')->where('server_id', strtolower($serverId))->first();

        if ($tunnel === null) {
            return;
        }

        $zones = CloudflareZone::query()->with('credential')->where('organization_id', $tunnel->organization_id)->get()
            ->filter(fn (CloudflareZone $zone) => $zone->credential->account_id === $tunnel->account_id);
        $hosts = [];

        foreach ($this->routes->compile($tunnel->server_id)['sites'] ?? [] as $site) {
            foreach ([...(array) ($site['domains'] ?? []), ...(array) ($site['redirect_domains'] ?? [])] as $host) {
                $host = strtolower((string) $host);
                if (! str_starts_with($host, '*.') && $zones->contains(fn (CloudflareZone $zone) => $zone->covers($host)) && ! CloudflareDns::reserved($host)) {
                    $hosts[$host] = true;
                }
            }
        }

        try {
            CloudflareApi::with($tunnel->credential->api_token)->configureTunnel($tunnel->account_id, $tunnel->tunnel_id, self::ingress(array_keys($hosts)));
            if ($tunnel->error !== null && str_starts_with($tunnel->error, 'Routes: ')) {
                $tunnel->forceFill(['error' => null])->save();
            }
        } catch (CloudflareError $e) {
            $tunnel->forceFill(['error' => 'Routes: '.$e->getMessage()])->save();

            throw $e;
        }
    }

    /**
     * cloudflared ingress rules: Let's Encrypt's HTTP-01 path to Caddy on :80, everything else to Caddy on :443 with
     * the name as SNI (Caddy has the certificate), then a 404 catch-all.
     *
     * @param  list<string>  $hosts
     * @return list<array<string, mixed>>
     */
    public static function ingress(array $hosts): array
    {
        sort($hosts);
        $rules = [];

        foreach ($hosts as $host) {
            $rules[] = ['hostname' => $host, 'path' => '^/\.well-known/acme-challenge/', 'service' => 'http://localhost:80'];
            $rules[] = ['hostname' => $host, 'service' => 'https://localhost:443', 'originRequest' => ['originServerName' => $host, 'httpHostHeader' => $host]];
        }

        $rules[] = ['service' => 'http_status:404'];

        return $rules;
    }

    /**
     * Live state from Cloudflare (null when it does not answer).
     *
     * @return array{status: string, connections: int}|null
     */
    public function health(CloudflareTunnel $tunnel): ?array
    {
        // Cached briefly: the settings page shows every tunnel, and Cloudflare's view changes slowly.
        return Cache::remember("edge:tunnel-health:{$tunnel->id}", 20, function () use ($tunnel) {
            try {
                return CloudflareApi::with($tunnel->credential->api_token)->tunnel($tunnel->account_id, $tunnel->tunnel_id);
            } catch (CloudflareError) {
                return null;
            }
        });
    }

    private function install(CloudflareTunnel $tunnel, ServerData $server): void
    {
        $arch = $server->arch === 'arm64' ? 'arm64' : 'amd64';
        $version = (string) config('edge.cloudflared.version');
        $payload = [
            'state' => 'present',
            'version' => $version,
            'url' => strtr((string) config('edge.cloudflared.url'), ['{version}' => $version, '{arch}' => $arch]),
            'sha256' => (string) config("edge.cloudflared.sha256.{$arch}"),
            'token' => $tunnel->token,
        ];

        try {
            $handle = $this->agents->dispatch($server->id, 'net.tunnel.apply', $payload, 300, "edge.tunnel.install:{$tunnel->id}:".Str::ulid());
            $tunnel->forceFill(['status' => CloudflareTunnel::INSTALLING, 'error' => null, 'command_id' => $handle->id])->save();
        } catch (AgentUnavailable $e) {
            $tunnel->forceFill(['status' => CloudflareTunnel::ERROR, 'error' => 'The server has no connected agent: '.$e->getMessage()])->save();
        }
    }

    /** DNS of the server's sites and its edge config (trusted proxies) follow the ingress mode. */
    private function changed(string $serverId): void
    {
        foreach ($this->sites->forServer($serverId) as $site) {
            SyncCloudflareDns::site($site->id);
        }
        $this->routes->schedule($serverId);
    }

    private function server(string $serverId, string $organizationId): ServerData
    {
        $server = $this->servers->find(strtolower($serverId));

        if ($server === null || $server->organizationId !== $organizationId) {
            throw ValidationException::withMessages(['server' => 'Unknown server.']);
        }

        return $server;
    }
}
