<?php

namespace Falak\Insights\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Falak\Identity\Events\OrganizationDeleted;

final class DeleteOrganizationInsights implements ShouldQueue
{
    public function handle(OrganizationDeleted $event): void
    {
        $org = $event->organizationId;
        $issueIds = DB::table('insights_issues')->where('organization_id', $org)->pluck('id');
        $monitorIds = DB::table('insights_heartbeat_monitors')->where('organization_id', $org)->pluck('id');

        foreach ($issueIds->chunk(500) as $chunk) {
            DB::table('insights_issue_users')->whereIn('issue_id', $chunk)->delete();
            DB::table('insights_issue_comments')->whereIn('issue_id', $chunk)->delete();
            DB::table('insights_issue_activities')->whereIn('issue_id', $chunk)->delete();
        }

        foreach ($monitorIds->chunk(500) as $chunk) {
            DB::table('insights_heartbeat_runs')->whereIn('monitor_id', $chunk)->delete();
        }

        foreach (['insights_exceptions', 'insights_aggregates', 'insights_issues', 'insights_thresholds', 'insights_heartbeat_monitors', 'insights_sites'] as $table) {
            DB::table($table)->where('organization_id', $org)->delete();
        }
    }
}
