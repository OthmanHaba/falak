<?php

namespace Kiln\Network\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Network\Application\ConvergePrivateNetwork;
use Kiln\Network\Domain\Enums\ApplyStatus;
use Kiln\Network\Domain\Enums\KeyStatus;
use Kiln\Network\Domain\Models\FirewallState;
use Kiln\Network\Domain\Models\PrivateNetworkMember;
use Kiln\Network\Events\FirewallApplied;
use Kiln\Network\Events\PrivateNetworkChanged;
use Kiln\Network\Infrastructure\WireGuardKeys;

/**
 * Settles firewall and WireGuard state from the outcome of the commands Network dispatched.
 * Only the currently tracked command id counts; results of superseded commands are ignored.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    public function __construct(private readonly ConvergePrivateNetwork $converge) {}

    public function handleFinished(CommandFinished $event): void
    {
        match ($event->type) {
            'net.firewall.apply' => $this->firewallApplied($event),
            'net.wireguard.apply' => $this->wireguardApplied($event),
            'system.write_file' => $this->keyInstalled($event),
            default => null,
        };
    }

    public function handleFailed(CommandFailed $event): void
    {
        $reason = mb_substr($event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : ''), 0, 1000);

        match ($event->type) {
            'net.firewall.apply' => FirewallState::query()
                ->where('command_id', $event->commandId)
                ->where('organization_id', $event->organizationId)
                ->update(['status' => ApplyStatus::Failed, 'error' => $reason, 'updated_at' => now()]),
            'net.wireguard.apply' => PrivateNetworkMember::query()
                ->where('command_id', $event->commandId)
                ->where('organization_id', $event->organizationId)
                ->update(['status' => ApplyStatus::Failed, 'error' => $reason, 'updated_at' => now()]),
            'system.write_file' => PrivateNetworkMember::query()
                ->where('key_command_id', $event->commandId)
                ->where('organization_id', $event->organizationId)
                ->update(['key_status' => KeyStatus::Failed, 'status' => ApplyStatus::Failed, 'error' => "Installing the WireGuard key failed: {$reason}", 'updated_at' => now()]),
            default => null,
        };
    }

    private function firewallApplied(CommandFinished $event): void
    {
        $state = FirewallState::query()->where('command_id', $event->commandId)->where('organization_id', $event->organizationId)->first();

        if (! $state) {
            return;
        }

        $sha = is_string($event->result['ruleset_sha256'] ?? null) ? $event->result['ruleset_sha256'] : null;

        $state->forceFill([
            'status' => ApplyStatus::Applied,
            'applied_hash' => $state->desired_hash,
            'ruleset_sha256' => $sha,
            'error' => null,
            'applied_at' => now(),
        ])->save();

        FirewallApplied::dispatch($state->server_id, $state->organization_id, $event->commandId, $sha);
    }

    private function keyInstalled(CommandFinished $event): void
    {
        $member = PrivateNetworkMember::query()->with('network')->where('key_command_id', $event->commandId)->where('organization_id', $event->organizationId)->first();

        if (! $member) {
            return;
        }

        // Custody of the private key moves to the host.
        $member->forceFill(['key_status' => KeyStatus::Installed, 'private_key' => null, 'error' => null])->save();

        ($this->converge)($member->network);
    }

    private function wireguardApplied(CommandFinished $event): void
    {
        $member = PrivateNetworkMember::query()->with('network')->where('command_id', $event->commandId)->where('organization_id', $event->organizationId)->first();

        if (! $member) {
            return;
        }

        $reported = $event->result['public_key'] ?? null;
        $adopt = is_string($reported) && WireGuardKeys::isPublicKey($reported) && $reported !== $member->public_key;

        $member->forceFill([
            'status' => ApplyStatus::Applied,
            'applied_hash' => $member->desired_hash,
            'applied_at' => now(),
            'error' => null,
            // The host already had a different key: peers must use the one it actually runs with.
            'public_key' => $adopt ? $reported : $member->public_key,
            'key_status' => KeyStatus::Installed,
            'private_key' => null,
        ])->save();

        if ($adopt) {
            ($this->converge)($member->network);
        }

        PrivateNetworkChanged::dispatch($member->network_id, $member->organization_id, PrivateNetworkChanged::APPLIED, [$member->server_id]);
    }
}
