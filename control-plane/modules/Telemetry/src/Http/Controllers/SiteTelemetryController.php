<?php

namespace Kiln\Telemetry\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Telemetry\Application\Queries\ServerMetricQueries;
use Kiln\Telemetry\Application\Queries\SiteMetricQueries;
use Kiln\Telemetry\Contracts\AccessLogs;
use Kiln\Telemetry\Contracts\Data\AccessLogEntry;
use Kiln\Telemetry\Contracts\Data\MetricSeries;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Kiln\Telemetry\Contracts\MetricsBackend;
use Kiln\Telemetry\Contracts\TelemetryLinks;

/**
 * JSON behind the service panel's Metrics and Logs tabs for one site (docs/UI_DESIGN.md §5.1).
 */
final class SiteTelemetryController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
    ) {}

    /** GET /telemetry/sites/{siteId}: the site, its servers and which backends are configured. */
    public function context(Request $request, string $siteId, TelemetryLinks $links): JsonResponse
    {
        $site = $this->site($request, $siteId);

        return response()->json([
            'site' => ['id' => $site->id, 'name' => $site->name],
            'servers' => $this->serversOf($site),
            'levels' => LogController::LEVELS,
            'ranges' => array_keys(ServerMetricQueries::RANGES),
            'configured' => [
                'logs' => (string) config('telemetry.loki.url') !== '',
                'traces' => (string) config('telemetry.tempo.url') !== '',
                'metrics' => (string) config('telemetry.metrics.query_url') !== '',
            ],
            'links' => [
                'logs' => $links->logs(['site_id' => $site->id]),
                'traces' => $links->traceSearch(['site_id' => $site->id]),
                'grafana' => $links->grafanaDashboard($site->organizationId, 'kiln-laravel', ['site' => $site->id]),
            ],
        ]);
    }

    /** GET /telemetry/sites/{siteId}/metrics/data?range=: CPU/memory per server, requests/errors/p95 for the site. */
    public function metrics(Request $request, string $siteId, MetricsBackend $metrics): JsonResponse
    {
        $site = $this->site($request, $siteId);
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(ServerMetricQueries::RANGES))]])['range'] ?? '1h';
        [$seconds, $step] = ServerMetricQueries::RANGES[$range];

        $end = CarbonImmutable::now();
        $start = $end->subSeconds($seconds);
        $charts = [];
        $errors = [];

        try {
            foreach (SiteMetricQueries::for($site->id, $site->serverIds()) as $key => $promql) {
                try {
                    $charts[$key] = array_map(fn (MetricSeries $s) => $s->toArray(), $metrics->queryRange($promql, $start, $end, $step));
                } catch (TelemetryQueryFailed $e) {
                    $charts[$key] = [];
                    $errors[$key] = $e->getMessage();
                }
            }
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'range' => $range,
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'step' => $step,
            'servers' => $this->serversOf($site),
            'charts' => $charts,
            'errors' => (object) $errors,
        ]);
    }

    /**
     * GET /telemetry/sites/{siteId}/access-logs/data?release=&deployment=&since=&before=&status=&method=&path=&limit=
     * — the site's edge access log (Network Logs of a deployment panel), newest first; `cursor` pages to older requests.
     */
    public function accessLogs(Request $request, string $siteId, AccessLogs $logs): JsonResponse
    {
        $site = $this->site($request, $siteId);
        $input = $request->validate([
            'release' => ['nullable', 'string', 'max:26'],
            'deployment' => ['nullable', 'string', 'max:26'],
            'server' => ['nullable', 'string', 'max:26'],
            'since' => ['nullable', 'date'],
            'before' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
            'status' => ['nullable', 'string', 'regex:/^([1-5]\d\d|[1-5]xx)$/i'],
            'method' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,16}$/'],
            'path' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        if ((string) config('telemetry.loki.url') === '') {
            return response()->json(['configured' => false, 'entries' => [], 'cursor' => null]);
        }

        $limit = (int) ($input['limit'] ?? 500);
        $end = isset($input['before'])
            ? CarbonImmutable::createFromTimestamp(intdiv((int) $input['before'], 1_000_000_000))->setMicrosecond(intdiv((int) $input['before'] % 1_000_000_000, 1000))
            : CarbonImmutable::now();
        // Loki bounds a query's range; a deployment's requests older than a week are not listed.
        $start = isset($input['since']) ? CarbonImmutable::parse($input['since'])->max($end->subDays(7)) : $end->subDay();
        $names = collect($this->serversOf($site))->pluck('name', 'id');

        try {
            $entries = $start->lessThan($end) ? $logs->forSite($site->organizationId, $site->id, $start, $end, [
                'release_id' => $input['release'] ?? null,
                'deployment_id' => $input['deployment'] ?? null,
                'server_id' => $input['server'] ?? null,
                'status' => $input['status'] ?? null,
                'method' => $input['method'] ?? null,
                'path' => $input['path'] ?? null,
            ], $limit) : [];
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'configured' => true,
            'entries' => array_map(fn (AccessLogEntry $e) => [...$e->toArray(), 'server' => $e->serverId !== null ? ($names[$e->serverId] ?? null) : null], $entries),
            'cursor' => count($entries) >= $limit ? $entries[array_key_last($entries)]->timestampNs : null,
        ]);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function serversOf(SiteData $site): array
    {
        $servers = [];

        foreach ($site->serverIds() as $serverId) {
            $server = $this->servers->find($serverId);

            if ($server !== null && $server->organizationId === $site->organizationId) {
                $servers[] = ['id' => $server->id, 'name' => $server->name];
            }
        }

        return $servers;
    }

    private function site(Request $request, string $siteId): SiteData
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');

        $site = $this->sites->find(strtolower($siteId));
        abort_if($site === null || $site->organizationId !== $organizationId, 404);

        return $site;
    }
}
