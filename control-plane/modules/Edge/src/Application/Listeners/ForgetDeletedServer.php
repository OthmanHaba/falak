<?php

namespace Falak\Edge\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\CertificateInstall;
use Falak\Edge\Domain\Models\CloudflareTunnel;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Edge\Domain\Models\ServerState;
use Falak\Edge\Domain\Models\Upstream;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareError;
use Falak\Servers\Events\ServerDeleted;

final class ForgetDeletedServer implements ShouldQueue
{
    public function __construct(private readonly EdgeChanges $changes) {}

    public function handle(ServerDeleted $event): void
    {
        ServerState::query()->whereKey($event->serverId)->delete();
        Upstream::query()->where('server_id', $event->serverId)->delete();
        CertificateInstall::query()->where('server_id', $event->serverId)->delete();

        // Its Cloudflare Tunnel is deleted at Cloudflare too (the server is gone, nothing to stop there).
        foreach (CloudflareTunnel::query()->with('credential')->where('server_id', $event->serverId)->get() as $tunnel) {
            try {
                CloudflareApi::with($tunnel->credential->api_token)->deleteTunnel($tunnel->account_id, $tunnel->tunnel_id);
            } catch (CloudflareError $e) {
                Log::warning('edge: could not delete the tunnel of a deleted server; delete it in Cloudflare', ['tunnel' => $tunnel->name, 'error' => $e->getMessage()]);
            }
            $tunnel->delete();
        }

        // Sites that were balanced by this server are served directly by their targets again.
        foreach (LoadBalancer::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->get() as $balancer) {
            $balancer->delete();
            $this->changes->siteChanged($balancer->site_id);
        }
    }
}
