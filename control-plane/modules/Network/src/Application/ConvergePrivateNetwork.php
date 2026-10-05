<?php

namespace Falak\Network\Application;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Enums\KeyStatus;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Network\Infrastructure\CanonicalJson;
use Falak\Network\Infrastructure\WireGuardPayloads;
use Illuminate\Support\Facades\DB;

/**
 * Converges every member of a private network:
 *  - members whose key is not on the host yet get it delivered (`system.write_file`, 0600 root);
 *  - members with an installed key get the full mesh (`net.wireguard.apply`) when their desired
 *    state differs from what is applied / in flight.
 *
 * Converges of one network are serialized with a row lock on the network, so concurrent triggers
 * (e.g. two key installs finishing on two workers) never reuse a revision key or record a desired
 * hash that belongs to another worker's payload; the last one to run sees every committed member.
 */
final class ConvergePrivateNetwork
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly WireGuardPayloads $payloads,
    ) {}

    public function __invoke(PrivateNetwork $network, bool $force = false): void
    {
        DB::transaction(function () use ($network, $force) {
            PrivateNetwork::query()->whereKey($network->id)->lockForUpdate()->first();
            $this->convergeMembers($network, $force);
        });
    }

    private function convergeMembers(PrivateNetwork $network, bool $force): void
    {
        foreach ($network->members()->get() as $member) {
            $member->setRelation('network', $network);

            if ($member->key_status === KeyStatus::Installed) {
                $this->applyMember($network, $member, $force);
            } elseif ($member->key_command_id === null || ($force && $member->key_status === KeyStatus::Failed)) {
                $this->dispatchKey($network, $member);
            }
        }
    }

    public function installKey(PrivateNetwork $network, PrivateNetworkMember $member): void
    {
        DB::transaction(function () use ($network, $member) {
            PrivateNetwork::query()->whereKey($network->id)->lockForUpdate()->first();
            $member->refresh();
            $this->dispatchKey($network, $member);
        });
    }

    private function dispatchKey(PrivateNetwork $network, PrivateNetworkMember $member): void
    {
        if ($member->private_key === null) {
            return;
        }

        $member->revision++;

        try {
            $handle = $this->agents->dispatch(
                $member->server_id,
                'system.write_file',
                $this->payloads->keyFile($network, $member->private_key),
                (int) config('network.command_timeout', 120),
                "net.wireguard.key:{$member->id}:{$member->revision}",
            );

            $member->forceFill(['key_status' => KeyStatus::Pending, 'key_command_id' => $handle->id, 'status' => ApplyStatus::Pending, 'error' => null]);
        } catch (AgentUnavailable) {
            $member->forceFill(['key_status' => KeyStatus::Failed, 'status' => ApplyStatus::Failed, 'error' => 'The server agent is not connected.']);
        }

        $member->save();
    }

    private function applyMember(PrivateNetwork $network, PrivateNetworkMember $member, bool $force): void
    {
        $payload = $this->payloads->apply($network, $member);
        $hash = CanonicalJson::hash($payload);

        if (! $force) {
            $converged = $member->status === ApplyStatus::Applied && $member->applied_hash === $hash;
            $inFlight = $member->status === ApplyStatus::Applying && $member->desired_hash === $hash;

            if ($converged || $inFlight) {
                return;
            }
        }

        $member->revision++;
        $member->desired_hash = $hash;

        try {
            $handle = $this->agents->dispatch(
                $member->server_id,
                'net.wireguard.apply',
                $payload,
                (int) config('network.wireguard_apply_timeout', 900),
                "net.wireguard:{$member->id}:{$member->revision}",
            );

            $member->forceFill(['status' => ApplyStatus::Applying, 'command_id' => $handle->id, 'error' => null]);
        } catch (AgentUnavailable) {
            $member->forceFill(['status' => ApplyStatus::Failed, 'command_id' => null, 'error' => 'The server agent is not connected.']);
        }

        $member->save();
    }
}
