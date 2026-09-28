<?php

namespace Kiln\Telemetry\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Telemetry\Application\Queries\LogQueryBuilder;
use Kiln\Telemetry\Contracts\Data\LogLine;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Kiln\Telemetry\Contracts\LogsQuery;

final class LogController extends Controller
{
    use ResolvesTimeRange;

    public const LEVELS = ['trace', 'debug', 'info', 'warn', 'error', 'fatal'];

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, ServerDirectory $servers, SiteDirectory $sites): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');

        return Inertia::render('Telemetry/Logs', [
            'filters' => $request->only(['server_id', 'site_id', 'service', 'level', 'search', 'regex', 'trace_id', 'range', 'from', 'to']),
            'servers' => array_map(fn (ServerData $s) => ['id' => $s->id, 'name' => $s->name], $servers->forOrganization($organizationId)),
            'sites' => array_map(fn (SiteData $s) => ['id' => $s->id, 'name' => $s->name], $sites->forOrganization($organizationId)),
            'levels' => self::LEVELS,
            'configured' => (string) config('telemetry.loki.url') !== '',
        ]);
    }

    public function data(Request $request, LogsQuery $logs): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');

        $filters = $request->validate([
            ...$this->timeRules(),
            'server_id' => ['nullable', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
            'site_id' => ['nullable', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
            'service' => ['nullable', 'string', 'max:255'],
            'compose_service' => ['nullable', 'string', 'max:63', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            // app: a site's own output; access: the edge's per-request log (the deployment panel's Network Logs).
            'kind' => ['nullable', Rule::in(['app', 'access'])],
            'level' => ['nullable', Rule::in(self::LEVELS)],
            'search' => ['nullable', 'string', 'max:500'],
            'regex' => ['nullable', 'boolean'],
            'trace_id' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{16,32}$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'direction' => ['nullable', Rule::in(['backward', 'forward'])],
            // Pagination cursor: only lines strictly older than this nanosecond timestamp.
            'before' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
        ]);

        [$from, $to] = $this->timeRange($request);

        if (! empty($filters['before'])) {
            $before = CarbonImmutable::createFromTimestampUTC(intdiv((int) $filters['before'], 1000) / 1_000_000);
            $to = $before->lessThan($to) ? $before : $to;
        }

        try {
            $logql = LogQueryBuilder::build($organizationId, [
                ...$filters,
                'regex' => $request->boolean('regex'),
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['search' => $e->getMessage()]);
        }

        $limit = (int) ($filters['limit'] ?? 200);

        try {
            $lines = $from->lessThan($to) ? $logs->queryRange($logql, $from, $to, $limit, $filters['direction'] ?? 'backward') : [];
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! empty($filters['before'])) {
            $lines = array_values(array_filter($lines, fn (LogLine $l) => self::olderThan($l->timestampNs, (string) $filters['before'])));
        }

        $last = $lines === [] ? null : $lines[array_key_last($lines)];

        return response()->json([
            'query' => $logql,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'lines' => array_map(fn (LogLine $l) => $l->toArray(), $lines),
            'next_before' => count($lines) >= $limit && $last ? $last->timestampNs : null,
        ]);
    }

    private static function olderThan(string $ns, string $before): bool
    {
        return strlen($ns) === strlen($before) ? strcmp($ns, $before) < 0 : strlen($ns) < strlen($before);
    }
}
