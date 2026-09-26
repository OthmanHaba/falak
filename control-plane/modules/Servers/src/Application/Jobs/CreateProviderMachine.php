<?php

namespace Kiln\Servers\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kiln\Providers\Contracts\Data\MachineSpec;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Contracts\ProviderGateway;
use Kiln\Servers\Application\ServerStatusUpdater;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Models\SshKey;
use Throwable;

/**
 * Creates the machine at the cloud provider. The cloud-init user data runs the kiln-agent installer,
 * after which enrollment (AgentEnrolled) moves the server on to provisioning.
 */
final class CreateProviderMachine implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Never retry blindly: a retried create could double-bill a machine. */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $serverId) {}

    public function handle(ProviderGateway $providers, ServerStatusUpdater $status): void
    {
        $server = Server::query()->find($this->serverId);

        if (! $server || $server->status !== ServerStatus::Creating || $server->provider_server_id !== null) {
            return;
        }

        try {
            $adapter = $providers->adapter($server->organization_id, (string) $server->provider_credential_id);

            $keyIds = $server->sshKeys->map(fn (SshKey $key) => $adapter->uploadSshKey("kiln-{$key->name}", $key->public_key))->values()->all();

            $machine = $adapter->createServer(new MachineSpec(
                name: $this->machineName($server),
                region: (string) $server->region,
                size: (string) $server->size,
                image: (string) $server->image,
                sshKeyIds: $keyIds,
                userData: $this->userData((string) $server->install_command),
                labels: ['kiln-server' => $server->id, 'kiln-org' => $server->organization_id, 'kiln-type' => $server->type->value],
            ));
        } catch (ProviderException $e) {
            $status->set($server, ServerStatus::Error, "Provider error: {$e->getMessage()}");

            return;
        } catch (Throwable $e) {
            $status->set($server, ServerStatus::Error, 'Could not create the machine at the provider.');

            throw $e;
        }

        // The server may have been deleted while the provider call was in flight: never leave an orphan machine.
        $current = Server::query()->find($server->id);

        if (! $current || $current->status === ServerStatus::Deleting) {
            $adapter->destroyServer($machine->id);

            return;
        }

        $server = $current;
        $server->forceFill([
            'provider_server_id' => $machine->id,
            'ipv4' => $machine->ipv4,
            'ipv6' => $machine->ipv6,
            'private_ipv4' => $machine->privateIpv4,
        ])->save();

        $status->set($server, ServerStatus::Creating, 'Machine created; waiting for the agent to enroll.');

        if ($machine->ipv4 === null) {
            RefreshProviderMachine::dispatch($server->id)->delay(now()->addSeconds(20));
        }
    }

    public function failed(?Throwable $e): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server && $server->status === ServerStatus::Creating && $server->provider_server_id === null) {
            app(ServerStatusUpdater::class)->set($server, ServerStatus::Error, 'Could not create the machine at the provider.');
        }
    }

    private function machineName(Server $server): string
    {
        return substr(trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($server->name)), '-'), 0, 50) ?: 'kiln-'.strtolower(substr($server->id, -8));
    }

    private function userData(string $installCommand): string
    {
        return "#!/bin/sh\n# kiln: install and enroll kiln-agent\nset -e\n{$installCommand}\n";
    }
}
