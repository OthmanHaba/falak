<?php

namespace Falak\Insights\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Insights\Domain\Models\HeartbeatMonitor;

final class UpdateHeartbeatMonitor
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array{enabled?: bool, grace_seconds?: ?int, timezone?: string, schedule?: ?string}  $data
     */
    public function __invoke(HeartbeatMonitor $monitor, array $data, string $userId): void
    {
        $monitor->forceFill(array_intersect_key($data, array_flip(['enabled', 'grace_seconds', 'timezone', 'schedule'])));

        // Re-derive the next expected run from the new schedule/timezone.
        if ($monitor->isDirty(['schedule', 'timezone']) || ($monitor->isDirty('enabled') && $monitor->enabled)) {
            $from = $monitor->last_scheduled_at ?? now();
            $monitor->next_expected_at = $monitor->cron()?->nextAfter($from->lessThan(now()) && $monitor->isDirty('enabled') ? now() : $from);
        }

        $changes = $monitor->getDirty();
        $monitor->save();

        $this->audit->record('insights.heartbeat.updated', 'insights_heartbeat', $monitor->id, array_intersect_key($changes, array_flip(['enabled', 'grace_seconds', 'timezone', 'schedule'])), $monitor->organization_id, $userId);
    }
}
