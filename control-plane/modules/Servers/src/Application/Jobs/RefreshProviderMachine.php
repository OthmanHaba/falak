<?php

namespace Falak\Servers\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderGateway;
use Falak\Servers\Domain\Models\Server;

/**
 * Polls the provider until the machine has a public IP (some providers assign it asynchronously).
 */
final class RefreshProviderMachine implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const MAX_POLLS = 15;

    public function __construct(public readonly string $serverId, public readonly int $poll = 1) {}

    public function handle(ProviderGateway $providers): void
    {
        $server = Server::query()->find($this->serverId);

        if (! $server || $server->provider_server_id === null || $server->ipv4 !== null) {
            return;
        }

        try {
            $machine = $providers->adapter($server->organization_id, (string) $server->provider_credential_id)->getServer($server->provider_server_id);
        } catch (ProviderException) {
            $machine = null;
        }

        if ($machine?->ipv4 !== null) {
            $server->forceFill(['ipv4' => $machine->ipv4, 'ipv6' => $machine->ipv6 ?? $server->ipv6, 'private_ipv4' => $machine->privateIpv4 ?? $server->private_ipv4])->save();

            return;
        }

        if ($this->poll < self::MAX_POLLS) {
            self::dispatch($this->serverId, $this->poll + 1)->delay(now()->addSeconds(20));
        }
    }
}
