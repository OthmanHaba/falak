<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Fleet\Events\AgentDatabasesReported;
use Illuminate\Support\Facades\Cache;

/**
 * Heartbeats report every database container on the server (`databases`): its health is recorded on the instance, and
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

            if (($item['secrets_missing'] ?? false) === true) {
                $this->restoreSecrets($instance);
            }
        }
    }

    private function restoreSecrets(DatabaseInstance $instance): void
    {
        if (! Cache::add("databases:secrets:{$instance->id}", true, (int) config('databases.secrets_restore_throttle', 300))) {
            return;
        }

        $this->commands->tryDispatch(
            $instance->server_id,
            'db.instance.secrets',
            ['id' => $instance->id, 'engine' => $instance->engine->protocol(), 'password' => $instance->root_password],
            (int) config('databases.timeouts.ddl', 300),
            "db.instance.secrets:{$instance->id}:".now()->format('YmdHis'),
        );
    }
}
