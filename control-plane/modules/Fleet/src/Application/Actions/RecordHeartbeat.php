<?php

namespace Falak\Fleet\Application\Actions;

use Falak\Fleet\Application\CommandRedelivery;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\AgentMetric;
use Falak\Fleet\Events\AgentCameOnline;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Fleet\Events\AgentSecretsMissing;
use Falak\Fleet\Events\AgentVersionChanged;
use Illuminate\Support\Carbon;

final class RecordHeartbeat
{
    public function __construct(private readonly CommandRedelivery $redelivery) {}

    /**
     * @param  array<string, mixed>  $heartbeat  validated heartbeat.schema.json document
     * @param  ?string  $session  the reporting agent process (X-Falak-Agent-Session)
     */
    public function __invoke(Agent $agent, array $heartbeat, ?string $ip = null, ?string $session = null): void
    {
        // A new process first: commands delivered to the previous one are redelivered or failed before the
        // running list below is applied.
        $this->redelivery->observeSession($agent, $session);

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

        // Commands the agent reports as running were evidently delivered (to this process).
        if ($running !== []) {
            $agent->commands()->whereIn('id', $running)->where('status', CommandStatus::Delivered)
                ->where(fn ($q) => $session === null ? $q->whereNull('delivered_session') : $q->where('delivered_session', $session))
                ->update(['status' => CommandStatus::Running, 'started_at' => now()]);
        }

        if ($wasOffline) {
            AgentCameOnline::dispatch($agent->id, $agent->organization_id, $agent->server_id, $previousHeartbeat?->toDateTimeImmutable());
        }

        $missing = array_values(array_unique(array_map('strval', (array) ($heartbeat['missing_secrets'] ?? []))));

        if ($missing !== [] && $agent->server_id !== null) {
            AgentSecretsMissing::dispatch($agent->id, $agent->organization_id, $agent->server_id, $missing);
        }

        if ($facts !== null) {
            AgentFactsReported::dispatch($agent->id, $agent->organization_id, $agent->server_id, $facts);

            if (is_string($facts['agent_version'] ?? null) && $facts['agent_version'] !== $previousVersion) {
                AgentVersionChanged::dispatch($agent->id, $agent->organization_id, $agent->server_id, $previousVersion, $facts['agent_version'], $agent->features());
            }
        }
    }
}
