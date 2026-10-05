<?php

namespace Falak\Servers\Application\Actions;

use Illuminate\Support\Str;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;
use Falak\Servers\Infrastructure\CommandPayloads;

/**
 * Converges authorized_keys for every managed unix user (system.ssh_key.sync, one command per user).
 * Skipped until the server is active: the users only exist after provisioning (ServerProvisioned re-syncs).
 */
final class SyncServerSshKeys
{
    public function __construct(private readonly AgentGateway $agents) {}

    /**
     * @return list<string> dispatched command ids
     */
    public function __invoke(Server $server): array
    {
        if ($server->status !== ServerStatus::Active) {
            return [];
        }

        $keys = $server->sshKeys()->get();
        $ids = [];

        foreach (AttachSshKey::UNIX_USERS as $user) {
            $forUser = $keys->filter(fn (SshKey $key) => $key->getRelationValue('pivot')?->getAttribute('unix_user') === $user)->values();
            $payload = CommandPayloads::sshKeySync($user, $forUser);

            try {
                $ids[] = $this->agents->dispatch($server->id, 'system.ssh_key.sync', $payload, 120, "ssh_keys:{$server->id}:{$user}:".Str::ulid())->id;
            } catch (AgentUnavailable) {
                return []; // Re-synced when the agent (re-)enrolls.
            }
        }

        $server->forceFill(['ssh_sync_command_id' => $ids[0] ?? null])->save();

        return $ids;
    }
}
