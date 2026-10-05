<?php

namespace Falak\Telemetry\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Application\Queries\TraceQueryBuilder;
use Falak\Telemetry\Contracts\Data\TraceSummary;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\TelemetryLinks;
use Falak\Telemetry\Contracts\TracesQuery;

final class TraceController extends Controller
{
    use ResolvesTimeRange;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, SiteDirectory $sites): Response
    {
        $organizationId = $this->authorizeView($request);

        return Inertia::render('Telemetry/Traces', [
            'filters' => $request->only(['site_id', 'service', 'name', 'status', 'min_duration_ms', 'range', 'from', 'to']),
            'sites' => array_map(fn (SiteData $s) => ['id' => $s->id, 'name' => $s->name], $sites->forOrganization($organizationId)),
            'configured' => (string) config('telemetry.tempo.url') !== '',
        ]);
    }

    public function search(Request $request, TracesQuery $traces): JsonResponse
    {
        $organizationId = $this->authorizeView($request);

        $filters = $request->validate([
            ...$this->timeRules(),
            'site_id' => ['nullable', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
            'service' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['any', 'error'])],
            'min_duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        [$from, $to] = $this->timeRange($request);
        $traceql = TraceQueryBuilder::build($organizationId, $filters);

        try {
            $results = $traces->search($traceql, $from, $to, (int) ($filters['limit'] ?? 50));
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'query' => $traceql,
            'traces' => array_map(fn (TraceSummary $t) => $t->toArray(), $results),
        ]);
    }

    public function show(Request $request, string $traceId, TelemetryLinks $links): Response
    {
        $this->authorizeView($request);
        $traceId = $this->traceId($traceId);

        return Inertia::render('Telemetry/Trace', [
            'traceId' => $traceId,
            'links' => [
                'logs' => $links->logs(['trace_id' => $traceId]),
                'grafana' => $links->grafanaTrace($traceId),
            ],
        ]);
    }

    public function data(Request $request, string $traceId, TracesQuery $traces): JsonResponse
    {
        $organizationId = $this->authorizeView($request);
        $traceId = $this->traceId($traceId);

        try {
            $trace = $traces->trace($traceId);
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        // Traces without (or with another) organization attribute are never revealed.
        if ($trace === null || strcasecmp((string) $trace->organizationId(), $organizationId) !== 0) {
            return response()->json(['message' => 'Trace not found.'], 404);
        }

        return response()->json(['trace' => $trace->toArray()]);
    }

    private function authorizeView(Request $request): string
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');

        return $organizationId;
    }

    private function traceId(string $traceId): string
    {
        abort_unless(preg_match('/^([0-9a-fA-F]{16}|[0-9a-fA-F]{32})$/', $traceId) === 1, 404);

        return strtolower($traceId);
    }
}
