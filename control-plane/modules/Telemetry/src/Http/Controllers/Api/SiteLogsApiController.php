<?php

namespace Kiln\Telemetry\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Telemetry\Application\Queries\LogQueryBuilder;
use Kiln\Telemetry\Contracts\Data\LogLine;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Kiln\Telemetry\Contracts\LogsQuery;
use Kiln\Telemetry\Http\Controllers\LogController;

/**
 * GET /api/v1/sites/{site}/logs?since=<seconds>&limit=&level=&cursor= — newest first; `meta.cursor`
 * pages to older lines (the `kiln logs` CLI).
 */
final class SiteLogsApiController extends Controller
{
    public function __invoke(Request $request, string $site, CurrentOrganization $organization, OrganizationAccess $access, SiteDirectory $sites, ServerDirectory $servers, LogsQuery $logs): JsonResponse
    {
        $organizationId = $organization->requireId();
        $access->authorize($request->user(), $organizationId, 'telemetry.view');

        $data = $sites->find(strtolower($site));

        if ($data === null || $data->organizationId !== $organizationId) {
            $data = collect($sites->forOrganization($organizationId))->first(fn ($s) => $s->slug === strtolower($site));
        }

        abort_if($data === null, 404, 'Site not found.');

        $input = $request->validate([
            'since' => ['nullable', 'integer', 'min:1', 'max:'.(30 * 86400)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'level' => ['nullable', Rule::in(LogController::LEVELS)],
            'cursor' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
        ]);

        $limit = (int) ($input['limit'] ?? 100);
        $end = now();
        $start = $end->copy()->subSeconds((int) ($input['since'] ?? 3600));

        if (! empty($input['cursor'])) {
            // Lines strictly older than the cursor (nanoseconds; Loki's end bound is exclusive).
            $end = now()->setTimestamp(intdiv((int) $input['cursor'], 1_000_000_000))->setMicrosecond(intdiv((int) $input['cursor'] % 1_000_000_000, 1000));
        }

        $logql = LogQueryBuilder::build($organizationId, ['site_id' => $data->id, 'level' => $input['level'] ?? null]);

        try {
            $lines = $start->lessThan($end) ? $logs->queryRange($logql, $start, $end, $limit, 'backward') : [];
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! empty($input['cursor'])) {
            $lines = array_values(array_filter($lines, fn (LogLine $l) => strlen($l->timestampNs) < strlen($input['cursor']) || (strlen($l->timestampNs) === strlen($input['cursor']) && strcmp($l->timestampNs, $input['cursor']) < 0)));
        }

        $names = [];
        $entries = array_map(function (LogLine $line) use ($servers, &$names) {
            $serverId = $line->labels['kiln_server_id'] ?? null;

            if ($serverId !== null && ! array_key_exists($serverId, $names)) {
                $names[$serverId] = $servers->find(strtolower($serverId))?->name;
            }

            $attributes = array_map('strval', array_merge($line->labels, $line->metadata));

            return [
                'at' => $line->at()->format('Y-m-d\TH:i:s.uP'),
                'level' => $line->metadata['severity_text'] ?? $line->labels['detected_level'] ?? $line->labels['level'] ?? null,
                'source' => $line->labels['service_name'] ?? $line->labels['source'] ?? $line->labels['job'] ?? null,
                'server' => $serverId !== null ? ($names[$serverId] ?? $serverId) : null,
                'message' => $line->line,
                'attributes' => $attributes === [] ? new \stdClass : $attributes,
            ];
        }, $lines);

        $last = $lines === [] ? null : $lines[array_key_last($lines)];

        return response()->json([
            'data' => $entries,
            'meta' => ['cursor' => count($lines) >= $limit && $last ? $last->timestampNs : ''],
        ]);
    }
}
