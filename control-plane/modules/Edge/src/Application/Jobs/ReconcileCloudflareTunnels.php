<?php

namespace Falak\Edge\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Falak\Edge\Application\CloudflareTunnels;
use Falak\Edge\Domain\Models\CloudflareTunnel;
use Falak\Edge\Domain\Models\OriginLock;
use Falak\Network\Contracts\Firewalls;

/**
 * Every few minutes: a tunnel Cloudflare reports down (cloudflared stopped after it was installed) is marked in error,
 * so a server whose web ports were closed behind it falls back to Cloudflare-only; a tunnel that is healthy again
 * comes back. The server's firewall is re-applied when that changes the lock-down.
 */
final class ReconcileCloudflareTunnels implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(CloudflareTunnels $tunnels, Firewalls $firewalls): void
    {
        foreach (CloudflareTunnel::query()->with('credential')->whereIn('status', [CloudflareTunnel::ACTIVE, CloudflareTunnel::ERROR])->get() as $tunnel) {
            Cache::forget("edge:tunnel-health:{$tunnel->id}");
            $health = $tunnels->health($tunnel);

            if ($health === null) {
                continue; // Cloudflare did not answer: keep the current state
            }

            $up = in_array($health['status'], ['healthy', 'degraded'], true) && $health['connections'] > 0;
            $wasActive = $tunnel->status === CloudflareTunnel::ACTIVE;

            if ($up === $wasActive || ($up && $tunnel->error !== null && ! str_starts_with($tunnel->error, 'Cloudflare reports'))) {
                continue;
            }

            $tunnel->forceFill($up
                ? ['status' => CloudflareTunnel::ACTIVE, 'error' => null]
                : ['status' => CloudflareTunnel::ERROR, 'error' => "Cloudflare reports the tunnel {$health['status']} ({$health['connections']} connections): is cloudflared running? (journalctl -u falak-cloudflared)"])->save();

            if (OriginLock::query()->whereKey($tunnel->server_id)->exists()) {
                $firewalls->converge($tunnel->server_id);
            }
        }
    }
}
