<?php

namespace Falak\Processes\Infrastructure;

use Falak\Processes\Contracts\Data\ScheduledJobData;
use Falak\Processes\Contracts\ScheduleDirectory;
use Falak\Processes\Domain\Models\ServerState;

/**
 * Reads the schedule set last sent to each server (processes_server_states.jobs).
 */
final class StateScheduleDirectory implements ScheduleDirectory
{
    public function forServer(string $serverId): array
    {
        $state = ServerState::query()->find(strtolower($serverId));

        return $state ? self::jobsOf($state) : [];
    }

    public function find(string $serverId, string $job): ?ScheduledJobData
    {
        foreach ($this->forServer($serverId) as $data) {
            if ($data->name === $job) {
                return $data;
            }
        }

        return null;
    }

    public function manages(string $serverId): bool
    {
        return ServerState::query()->whereKey(strtolower($serverId))->whereNotNull('cron_sha256')->exists();
    }

    /**
     * @return list<ScheduledJobData>
     */
    public static function jobsOf(ServerState $state): array
    {
        $jobs = [];

        foreach ($state->jobs ?? [] as $name => $meta) {
            $jobs[] = new ScheduledJobData(
                name: (string) $name,
                serverId: $state->server_id,
                organizationId: $state->organization_id,
                siteId: (string) $meta['site_id'],
                kind: (string) $meta['kind'],
                label: (string) $meta['label'],
                schedule: (string) $meta['schedule'],
                timezone: (string) ($meta['timezone'] ?? 'UTC'),
                heartbeat: (bool) ($meta['heartbeat'] ?? true),
            );
        }

        return $jobs;
    }
}
