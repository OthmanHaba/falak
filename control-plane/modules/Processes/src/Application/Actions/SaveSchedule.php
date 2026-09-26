<?php

namespace Kiln\Processes\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Processes\Application\ServerConverger;
use Kiln\Processes\Domain\Models\Schedule;
use Kiln\Sites\Contracts\Data\SiteData;

/**
 * Create or update a custom scheduled job, then converge the site's servers.
 */
final class SaveSchedule
{
    public function __construct(
        private readonly ServerConverger $converger,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function __invoke(SiteData $site, ?Schedule $schedule, array $data, ?string $userId): Schedule
    {
        $schedule ??= new Schedule(['organization_id' => $site->organizationId, 'site_id' => $site->id, 'created_by' => $userId]);

        $schedule->fill([
            'name' => trim((string) $data['name']),
            'command' => trim((string) $data['command']),
            'expression' => preg_replace('/\s+/', ' ', trim((string) $data['expression'])),
            'timezone' => ($data['timezone'] ?? null) ?: 'UTC',
            'user' => ($data['user'] ?? null) ?: null,
            'overlap' => ($data['overlap'] ?? null) ?: 'skip',
            'timeout' => (int) ($data['timeout'] ?? 3600),
            'heartbeat' => (bool) ($data['heartbeat'] ?? true),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'all_servers' => (bool) ($data['all_servers'] ?? false),
        ]);

        $created = ! $schedule->exists;
        $schedule->save();

        $this->audit->record($created ? 'processes.schedule_created' : 'processes.schedule_updated', 'site', $site->id, [
            'schedule_id' => $schedule->id,
            'name' => $schedule->name,
            'expression' => $schedule->expression,
            'command' => $schedule->command,
        ], $site->organizationId);

        $this->converger->schedule(...$site->serverIds());

        return $schedule;
    }
}
