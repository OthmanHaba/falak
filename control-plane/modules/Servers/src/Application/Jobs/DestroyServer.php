<?php

namespace Kiln\Servers\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kiln\Fleet\Contracts\Enrollment;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Contracts\ProviderGateway;
use Kiln\Servers\Application\ServerStatusUpdater;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\ServerDeleted;

/**
 * Revokes the agent, destroys the machine at the provider (idempotent) and removes the server.
 */
final class DestroyServer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public readonly string $serverId, public readonly bool $destroyAtProvider = true) {}

    public function handle(Enrollment $enrollment, ProviderGateway $providers, ServerStatusUpdater $status, AuditLog $audit): void
    {
        $server = Server::query()->find($this->serverId);

        if (! $server) {
            return;
        }

        $enrollment->revokeServer($server->id, 'server deleted');

        if ($this->destroyAtProvider && $server->provider_credential_id && $server->provider_server_id) {
            // A removed credential is permanent: retrying cannot succeed.
            if (! $providers->credential($server->organization_id, $server->provider_credential_id)) {
                $status->set($server, ServerStatus::Error, 'The provider credential for this server was removed. Delete the server without destroying it at the provider, then remove the machine in the provider console.');

                return;
            }

            try {
                $providers->adapter($server->organization_id, $server->provider_credential_id)->destroyServer($server->provider_server_id);
            } catch (ProviderException $e) {
                if ($this->attempts() >= $this->tries) {
                    $status->set($server, ServerStatus::Error, "Could not delete the machine at the provider: {$e->getMessage()}");

                    return;
                }

                throw $e;
            }
        }

        $data = [$server->id, $server->organization_id, $server->type->value, $server->name];
        $server->delete();

        $audit->record('server.deleted', 'server', $data[0], ['name' => $data[3]], $data[1]);
        ServerDeleted::dispatch(...$data);
    }
}
