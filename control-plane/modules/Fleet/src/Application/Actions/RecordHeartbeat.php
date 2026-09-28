<?php

namespace Kiln\Fleet\Application\Actions;

use Illuminate\Support\Carbon;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\AgentMetric;
use Kiln\Fleet\Events\AgentCameOnline;
use Kiln\Fleet\Events\AgentFactsReported;
use Kiln\Fleet\Events\AgentVersionChanged;

final class RecordHeartbeat
{
    /**
     * @param  array<string, mixed>  $heartbeat  validated heartbeat.schema.json document
     */
    public function __invoke(Agent $agent, array $heartbeat, ?string $ip = null): void
    {
        $wasOffline = $agent->status === AgentStatus::Offline;
        $previousHeartbeat = $agent->last_heartbeat_at;
        $at = Carbon::parse((string) $heartbeat['at']);
        $load = array_map('floatval', (array) $heartbeat['load']);
        $running = array_values(array_map('strval', (array) ($heartbeat['running_commands'] ?? [])));

        $attributes = [
            'status' => AgentStatus::Online,
            'last_heartbeat_at' => now(),
            'last_ip' => $ip,
            'metrics' => [
                'at' => $at->toIso8601String(),
                'uptime_s' => (int) $heartbeat['uptime_s'],
                'load' => $load,
                'cpu_percent' => isset($heartbeat['cpu_percent']) ? (float) $heartbeat['cpu_percent'] : null,
                'memory_used_bytes' => (int) $heartbeat['memory_used_bytes'],
                'disk_used_bytes' => (int) $heartbeat['disk_used_bytes'],
                'running_commands' => $running,
            ],
        ];

        $facts = isset($heartbeat['facts']) && is_array($heartbeat['facts']) ? $heartbeat['facts'] : null;
        $previousVersion = $agent->agent_version;

        if ($facts !== null) {
            $attributes += [
                'facts' => $facts,
                'hostname' => $facts['hostname'] ?? $agent->hostname,
                'arch' => $facts['arch'] ?? $agent->arch,
                'agent_version' => $facts['agent_version'] ?? $agent->agent_version,
            ];
        }

        $agent->forceFill($attributes)->save();

        AgentMetric::query()->create([
            'agent_id' => $agent->id,
            'server_id' => $agent->server_id,
            'at' => $at,
            'uptime_s' => (int) $heartbeat['uptime_s'],
            'load1' => $load[0] ?? 0,
            'load5' => $load[1] ?? 0,
            'load15' => $load[2] ?? 0,
            'cpu_percent' => isset($heartbeat['cpu_percent']) ? (float) $heartbeat['cpu_percent'] : null,
            'memory_used_bytes' => (int) $heartbeat['memory_used_bytes'],
            'disk_used_bytes' => (int) $heartbeat['disk_used_bytes'],
        ]);

        // Commands the agent reports as running were evidently delivered.
        if ($running !== []) {
            $agent->commands()->whereIn('id', $running)->where('status', CommandStatus::Delivered)
                ->update(['status' => CommandStatus::Running, 'started_at' => now()]);
        }

        if ($wasOffline) {
            AgentCameOnline::dispatch($agent->id, $agent->organization_id, $agent->server_id, $previousHeartbeat?->toDateTimeImmutable());
        }

        if ($facts !== null) {
            AgentFactsReported::dispatch($agent->id, $agent->organization_id, $agent->server_id, $facts);

            if (is_string($facts['agent_version'] ?? null) && $facts['agent_version'] !== $previousVersion) {
                AgentVersionChanged::dispatch($agent->id, $agent->organization_id, $agent->server_id, $previousVersion, $facts['agent_version'], $agent->features());
            }
        }
    }
}
