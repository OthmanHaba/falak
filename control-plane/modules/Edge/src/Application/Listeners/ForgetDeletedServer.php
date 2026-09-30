<?php

namespace Kiln\Edge\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Domain\Models\CertificateInstall;
use Kiln\Edge\Domain\Models\CloudflareTunnel;
use Kiln\Edge\Domain\Models\LoadBalancer;
use Kiln\Edge\Domain\Models\ServerState;
use Kiln\Edge\Domain\Models\Upstream;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareError;
use Kiln\Servers\Events\ServerDeleted;

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
            $tunnel->delete();
            try {
                CloudflareApi::with($tunnel->credential->api_token)->deleteTunnel($tunnel->account_id, $tunnel->tunnel_id);
            } catch (CloudflareError) {
                // best effort: it can be deleted in Cloudflare's dashboard
            }
        }

        // Sites that were balanced by this server are served directly by their targets again.
        foreach (LoadBalancer::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->get() as $balancer) {
            $balancer->delete();
            $this->changes->siteChanged($balancer->site_id);
        }
    }
}
