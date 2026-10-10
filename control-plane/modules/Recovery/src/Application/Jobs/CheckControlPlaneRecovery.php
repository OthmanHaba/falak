<?php

namespace Falak\Recovery\Application\Jobs;

use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Recovery\Application\ControlPlaneNotice;
use Falak\Recovery\Application\ControlPlaneStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly (scheduler): the control plane's DR status file turned into the operator organization's alerts.
 *
 * - dr.not_configured: a reminder every recovery.reminder_days until falak-ctl dr setup ran.
 * - dr.backup_failed: once per failed scheduled backup.
 * - dr.backup_missing: no backup within twice the schedule; resolved by the next backup.
 * - dr.drill_failed: once per failed drill.
 */
final class CheckControlPlaneRecovery implements ShouldQueue
{
    use Queueable;

    public const NOT_CONFIGURED = 'dr.not_configured';

    public const BACKUP_FAILED = 'dr.backup_failed';

    public const BACKUP_MISSING = 'dr.backup_missing';

    public const DRILL_FAILED = 'dr.drill_failed';

    private const URL = '/settings/disaster-recovery';

    public function handle(ControlPlaneStatus $status, ControlPlaneNotice $notice, Alerts $alerts): void
    {
        $organizationId = $notice->operatorOrganizationId();

        if ($organizationId === null) {
            return;
        }

        if ($status->needsSetup()) {
            $period = intdiv(now()->getTimestamp(), 86400 * max(1, (int) config('recovery.reminder_days', 7)));
            $alerts->raise(new AlertData($organizationId, self::NOT_CONFIGURED, Severity::Warning,
                'Disaster recovery for the control plane is not configured',
                'Backups of the control plane stay on its own host. Run `falak-ctl dr setup` on it to send encrypted backups to a bucket every few hours.',
                url(self::URL), self::NOT_CONFIGURED.":{$period}"));

            return;
        }

        if (! $status->configured()) {
            return;
        }

        if ($status->backupFailing()) {
            $at = (string) $status->lastFailureAt()?->toIso8601String();
            $alerts->raise(new AlertData($organizationId, self::BACKUP_FAILED, Severity::Critical, 'A scheduled control plane backup failed',
                (string) $status->lastFailureError(), url(self::URL), self::BACKUP_FAILED.":{$at}", context: ['failed_at' => $at]));
        }

        $last = $status->lastBackupAt();
        $alerts->raise($status->backupMissing()
            ? new AlertData($organizationId, self::BACKUP_MISSING, Severity::Critical, 'No recent control plane backup',
                $last !== null ? "The last one is from {$last->toIso8601String()}, more than twice the {$status->scheduleHours()} h schedule ago." : 'No backup was recorded yet.',
                url(self::URL), self::BACKUP_MISSING, context: ['last_backup_at' => $last?->toIso8601String()])
            : new AlertData($organizationId, self::BACKUP_MISSING, Severity::Info, 'Control plane backups are recent again', '', url(self::URL), self::BACKUP_MISSING, resolves: true));

        if ($status->drillFailed()) {
            $at = (string) $status->lastDrillAt()?->toIso8601String();
            $alerts->raise(new AlertData($organizationId, self::DRILL_FAILED, Severity::Critical, 'The control plane restore drill failed',
                (string) ($status->toArray()['last_drill']['message'] ?? ''), url(self::URL), self::DRILL_FAILED.":{$at}", context: ['drill_at' => $at]));
        }
    }
}
