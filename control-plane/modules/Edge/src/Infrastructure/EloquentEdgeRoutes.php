<?php

namespace Kiln\Edge\Infrastructure;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kiln\Edge\Application\ComposeServiceDomains;
use Kiln\Edge\Application\Jobs\ApplyEdgeConfig;
use Kiln\Edge\Contracts\Data\DomainData;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Enums\ApplyStatus;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Domain\Models\ServerState;
use Kiln\Edge\Domain\Models\Upstream;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Data\CommandHandle;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\SiteDirectory;

final class EloquentEdgeRoutes implements EdgeRoutes
{
    public function __construct(
        private readonly RouteCompiler $compiler,
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
        private readonly int $applyDelaySeconds = 2,
        private readonly int $applyTimeoutSeconds = 120,
        private readonly string $testDomainTls = 'acme',
    ) {}

    public function testDomainTls(): TlsMode
    {
        return $this->testDomainTls === 'internal' ? TlsMode::Internal : TlsMode::Auto;
    }

    public function compile(string $serverId): array
    {
        return $this->compiler->compile($serverId);
    }

    public function apply(string $serverId, bool $force = false): ?CommandHandle
    {
        $server = $this->servers->find($serverId);

        if ($server === null || ! $server->type->servesHttp()) {
            return null;
        }

        $payload = $this->compiler->compile($serverId);
        $sha = PayloadHash::of($payload);
        $state = ServerState::query()->find($serverId);

        // Nothing was ever routed here and nothing is now: leave the host's edge alone.
        if ($state === null && $payload['sites'] === [] && ! $force) {
            return null;
        }

        if (! $force && $state && $state->payload_sha256 === $sha && in_array($state->status, [ApplyStatus::Pending, ApplyStatus::Applied], true)) {
            return null;
        }

        $state ??= new ServerState(['server_id' => $serverId]);
        $state->organization_id = $server->organizationId;

        try {
            $handle = $this->agents->dispatch($serverId, 'edge.caddy.apply', $payload, $this->applyTimeoutSeconds, "edge.apply:{$serverId}:".Str::ulid());
        } catch (AgentUnavailable) {
            $state->forceFill(['payload_sha256' => $sha, 'status' => ApplyStatus::Error, 'command_id' => null, 'error' => 'The server agent is not connected.'])->save();

            return null;
        } catch (InvalidCommandPayload $e) {
            Log::error('edge: compiled an invalid edge.caddy.apply payload', ['server_id' => $serverId, 'error' => $e->getMessage()]);
            $state->forceFill(['payload_sha256' => $sha, 'status' => ApplyStatus::Error, 'command_id' => null, 'error' => 'Compiled configuration is invalid: '.Str::limit($e->getMessage(), 900)])->save();

            return null;
        }

        $state->forceFill([
            'payload_sha256' => $sha,
            'octane_sites' => RouteCompiler::octaneSites($payload),
            'command_id' => $handle->id,
            'status' => ApplyStatus::Pending,
            'error' => null,
            'dispatched_at' => now(),
        ])->save();

        return $handle;
    }

    public function schedule(string ...$serverIds): void
    {
        foreach (array_unique(array_filter($serverIds)) as $serverId) {
            ApplyEdgeConfig::dispatch($serverId)->delay(now()->addSeconds($this->applyDelaySeconds));
        }
    }

    public function recordUpstream(string $siteId, string $serverId, string $upstream): void
    {
        $current = Upstream::query()->where('site_id', $siteId)->where('server_id', $serverId)->first();

        if ($current?->upstream === $upstream) {
            return;
        }

        Upstream::query()->updateOrCreate(['site_id' => $siteId, 'server_id' => $serverId], ['upstream' => $upstream]);

        $this->schedule($serverId);
    }

    public function routeId(string $siteId): string
    {
        return RouteCompiler::routeId($siteId);
    }

    public function domainsFor(string $siteId, ?string $service = null): array
    {
        $query = Domain::query()->where('site_id', $siteId);
        $site = app(SiteDirectory::class)->find($siteId);

        return ($site !== null ? ComposeServiceDomains::scope($query, $site, $service) : $query->whereNull('compose_service'))
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(fn (Domain $domain): DomainData => $domain->toData())
            ->values()
            ->all();
    }

    public function proxiesToOctane(string $siteId, string $serverId): bool
    {
        $state = ServerState::query()->find($serverId);

        if ($state === null) {
            return false;
        }

        $siteId = strtolower($siteId);

        return in_array($siteId, $state->applied_octane_sites ?? [], true)
            || ($state->status === ApplyStatus::Pending && in_array($siteId, $state->octane_sites ?? [], true));
    }
}
