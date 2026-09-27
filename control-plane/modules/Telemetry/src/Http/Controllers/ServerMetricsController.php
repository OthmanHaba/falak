<?php

namespace Kiln\Telemetry\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Telemetry\Application\Queries\ServerMetricQueries;
use Kiln\Telemetry\Contracts\Data\MetricSeries;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Kiln\Telemetry\Contracts\MetricsBackend;

final class ServerMetricsController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    /** The classic server metrics page moved into the server panel's Metrics tab (which reads data() below). */
    public function show(Request $request, string $serverId): RedirectResponse
    {
        $server = $this->server($request, $serverId);

        return redirect("/servers/{$server->id}/metrics");
    }

    public function data(Request $request, string $serverId, MetricsBackend $metrics): JsonResponse
    {
        $server = $this->server($request, $serverId);
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(ServerMetricQueries::RANGES))]])['range'] ?? '1h';
        [$seconds, $step] = ServerMetricQueries::RANGES[$range];

        $end = CarbonImmutable::now();
        $start = $end->subSeconds($seconds);
        $charts = [];
        $errors = [];

        try {
            foreach (ServerMetricQueries::for($server->id) as $key => $promql) {
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
            'charts' => $charts,
            'errors' => (object) $errors,
        ]);
    }

    private function server(Request $request, string $serverId): ServerData
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');

        abort_unless(preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $serverId) === 1, 404);
        $server = $this->servers->find($serverId);
        abort_if($server === null || $server->organizationId !== $organizationId, 404);

        return $server;
    }
}
