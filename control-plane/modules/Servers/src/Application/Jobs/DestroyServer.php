<?php

namespace Falak\Servers\Application\Jobs;

use Falak\Fleet\Contracts\Enrollment;
use Falak\Identity\Contracts\AuditLog;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderGateway;
use Falak\Servers\Application\ServerStatusUpdater;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Events\ServerDeleted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

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
