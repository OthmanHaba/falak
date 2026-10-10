<?php

namespace Falak\Security\Application\Jobs;

use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Security\Application\Actions\StartAudit;
use Falak\Security\Domain\Enums\AuditStatus;
use Falak\Security\Domain\Models\Audit;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Hourly: audits every active server whose last audit is older than security.audit_every_hours (daily by default; the
 * hour a server was provisioned spreads the load), gives up audits that never answered, and prunes old history.
 */
final class RunDueAudits implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(OrganizationDirectory $organizations, ServerDirectory $servers, StartAudit $start): void
    {
        Audit::query()
            ->where('status', AuditStatus::Running)
            ->where('created_at', '<', now()->subMinutes((int) config('security.stale_minutes', 30)))
            ->update(['status' => AuditStatus::Failed, 'error' => 'The agent did not answer.', 'updated_at' => now()]);

        $due = now()->subHours(max(1, (int) config('security.audit_every_hours', 24)))->addMinutes(5);

        foreach ($organizations->all() as $organization) {
            foreach ($servers->forOrganization($organization->id, activeOnly: true) as $server) {
                $recent = Audit::query()->where('server_id', $server->id)->whereIn('status', [AuditStatus::Running, AuditStatus::Completed])->where('created_at', '>', $due)->exists();

                if (! $recent) {
                    $start($server, 'scheduled');
                }
            }
        }

        // Keep the history behind the score graph, and every server's latest audit whatever its age.
        $cutoff = now()->subDays((int) config('security.history_days', 90));
        $latest = Audit::query()->selectRaw('max(id) as id')->groupBy('server_id')->pluck('id')->all(); // ULIDs sort by time
        Audit::query()->where('created_at', '<', $cutoff)->whereNotIn('id', $latest)->delete();
    }
}
