<?php

namespace Kiln\Servers\Application;

use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\ServerUpdated;

/**
 * Copies agent-reported host facts (facts.schema.json) onto the server.
 */
final class ServerFacts
{
    /**
     * @param  array<string, mixed>  $facts
     */
    public function record(Server $server, array $facts): void
    {
        $os = is_array($facts['os'] ?? null) ? trim(($facts['os']['id'] ?? '').' '.($facts['os']['version'] ?? '')) : null;

        $server->forceFill([
            'facts' => $facts,
            'os' => $os ?: $server->os,
            'arch' => $facts['arch'] ?? $server->arch,
            'cpus' => isset($facts['cpus']) ? (int) $facts['cpus'] : $server->cpus,
            'memory_bytes' => isset($facts['memory_bytes']) ? (int) $facts['memory_bytes'] : $server->memory_bytes,
            'disk_bytes' => isset($facts['disk_bytes']) ? (int) $facts['disk_bytes'] : $server->disk_bytes,
            'ipv4' => $server->ipv4 ?? ($facts['public_ipv4'] ?? null),
            'private_ipv4' => $this->privateIpv4($server, $facts),
        ])->save();

        ServerUpdated::dispatch($server->id, $server->status->value, $server->status_message, $server->provision_command_id);
    }

    /**
     * The agent's private address wins. When it reports none, an address the agent reported before is cleared (agents
     * before v0.5.2 reported Docker's bridge, 172.17.0.1, which is not reachable from other servers); one from the
     * provider stays.
     *
     * @param  array<string, mixed>  $facts
     */
    private function privateIpv4(Server $server, array $facts): ?string
    {
        if ($reported = ($facts['private_ipv4'] ?? null) ?: null) {
            return (string) $reported;
        }

        $previous = is_array($server->facts) ? ($server->facts['private_ipv4'] ?? null) : null;

        if (array_key_exists('private_ipv4', $facts) && $server->private_ipv4 !== null && $server->private_ipv4 === $previous) {
            return null;
        }

        return $server->private_ipv4;
    }
}
