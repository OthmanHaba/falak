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
