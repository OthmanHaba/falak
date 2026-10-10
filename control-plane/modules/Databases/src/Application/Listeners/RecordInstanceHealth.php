<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Fleet\Events\AgentDatabasesReported;
use Illuminate\Support\Facades\Cache;

/**
 * Heartbeats report every database container on the server (`databases`): its health (and, with point-in-time recovery,
 * the state of its spool) is recorded on the instance, and
 * a container whose password file is gone (a reboot emptied /run) gets it back with db.instance.secrets, at most once
 * per instance every databases.secrets_restore_throttle seconds (heartbeats repeat it until it is restored).
 */
final class RecordInstanceHealth
{
    public function __construct(private readonly AgentCommands $commands) {}

    public function handle(AgentDatabasesReported $event): void
    {
        $reported = [];

        foreach ($event->instances as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $reported[strtolower($item['id'])] = $item;
            }
        }

        $instances = DatabaseInstance::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)
            ->whereIn('status', [InstanceStatus::Active, InstanceStatus::Upgrading])->get();

        foreach ($instances as $instance) {
            $item = $reported[$instance->id] ?? null;
            $state = (string) ($item['state'] ?? 'missing');
            $health = $state === 'running' ? (string) ($item['health'] ?? 'none') : ($state === 'missing' ? 'missing' : 'stopped');

            if ($instance->health !== $health) {
                $instance->forceFill(['health' => $health, 'health_at' => now()])->save();
            }

            // Point-in-time recovery: the spool as the agent sees it (MaintainPitr alerts on it, the UI shows it).
            if (is_array($item['pitr'] ?? null)) {
                $instance->forceFill(['pitr_report' => [...self::pitr($item['pitr']), 'at' => now()->toIso8601String()]])->save();
            }

            if (($item['secrets_missing'] ?? false) === true) {
                $this->restoreSecrets($instance);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function pitr(array $report): array
    {
        return [
            'spool_bytes' => max(0, (int) ($report['spool_bytes'] ?? 0)),
            'volume_bytes' => max(0, (int) ($report['volume_bytes'] ?? 0)),
            'pending' => max(0, (int) ($report['pending'] ?? 0)),
            'oldest_pending_at' => is_string($report['oldest_pending_at'] ?? null) ? $report['oldest_pending_at'] : null,
            'last_shipped_at' => is_string($report['last_shipped_at'] ?? null) ? $report['last_shipped_at'] : null,
            'error' => is_string($report['error'] ?? null) ? mb_substr($report['error'], 0, 500) : null,
        ];
    }

    private function restoreSecrets(DatabaseInstance $instance): void
    {
        if (! Cache::add("databases:secrets:{$instance->id}", true, (int) config('databases.secrets_restore_throttle', 300))) {
            return;
        }

        $this->commands->tryDispatch(
            $instance->server_id,
            'db.instance.secrets',
            array_filter(['id' => $instance->id, 'engine' => $instance->engine->protocol(), 'password' => $instance->root_password, 'previous' => $instance->engine->isKeyValue() ? $instance->previous_password : null]),
            (int) config('databases.timeouts.ddl', 300),
            "db.instance.secrets:{$instance->id}:".now()->format('YmdHis'),
        );
    }
}
