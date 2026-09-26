<?php

namespace Kiln\Insights\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Insights\Application\Queries\SiteOverview;
use Kiln\Insights\Contracts\IssueStatus;
use Kiln\Insights\Contracts\SiteNameResolver;
use Kiln\Kernel\Http\Controller;
use Kiln\Telemetry\Contracts\TelemetryLinks;

final class OverviewController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteNameResolver $sites,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'insights.view');

        $sites = DB::table('insights_sites')->where('organization_id', $organizationId)->orderByDesc('last_seen_at')->get();
        $siteIds = $sites->pluck('site_id')->all();
        $names = $this->sites->names($siteIds);
        $since = now()->subDay();

        $requests = DB::table('insights_aggregates')
            ->where('organization_id', $organizationId)
            ->where('event_type', 'request')
            ->where('minute', '>=', $since)
            ->groupBy('site_id')
            ->selectRaw('site_id, SUM(count) AS total, SUM(errors) AS errors, SUM(p95_ms * count) AS weighted_p95')
            ->get()
            ->keyBy('site_id');

        $exceptions = DB::table('insights_exceptions')
            ->where('organization_id', $organizationId)
            ->where('minute', '>=', $since)
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) AS total')
            ->pluck('total', 'site_id');

        $openIssues = DB::table('insights_issues')
            ->where('organization_id', $organizationId)
            ->where('status', IssueStatus::Open->value)
            ->whereNotNull('site_id')
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) AS total')
            ->pluck('total', 'site_id');

        return Inertia::render('Insights/Index', [
            'sites' => $sites->map(function ($site) use ($names, $requests, $exceptions, $openIssues) {
                $req = $requests->get($site->site_id);
                $total = (int) ($req->total ?? 0);

                return [
                    'id' => $site->site_id,
                    'name' => $names[$site->site_id] ?? $site->site_id,
                    'last_seen_at' => Carbon::parse($site->last_seen_at)->toIso8601String(),
                    'requests_24h' => $total,
                    'errors_24h' => (int) ($req->errors ?? 0),
                    'p95_ms_24h' => $total > 0 ? round((float) $req->weighted_p95 / $total, 1) : null,
                    'exceptions_24h' => (int) ($exceptions[$site->site_id] ?? 0),
                    'open_issues' => (int) ($openIssues[$site->site_id] ?? 0),
                ];
            })->values(),
            'openIssues' => (int) DB::table('insights_issues')->where('organization_id', $organizationId)->where('status', IssueStatus::Open->value)->count(),
        ]);
    }

    public function show(Request $request, string $siteId, SiteOverview $overview, TelemetryLinks $links): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'insights.view');
        $siteId = strtolower($siteId);

        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(SiteOverview::RANGES))]])['range'] ?? '24h';
        $data = $overview($organizationId, $siteId, $range);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        return Inertia::render('Insights/Overview', [
            'site' => ['id' => $siteId, 'name' => $this->sites->name($siteId)],
            'overview' => $data,
            'ranges' => array_keys(SiteOverview::RANGES),
            'links' => [
                'logs' => $links->logs(['site_id' => $siteId], $from, $to),
                'errorLogs' => $links->logs(['site_id' => $siteId, 'search' => 'error'], $from, $to),
                'traces' => $links->traceSearch(['site_id' => $siteId], $from, $to),
                'slowTraces' => $links->traceSearch(['site_id' => $siteId, 'min_duration_ms' => 1000], $from, $to),
                'grafana' => $links->grafanaDashboard($organizationId, 'kiln-laravel', ['site' => $siteId]),
            ],
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'insights.manage')],
        ]);
    }
}
