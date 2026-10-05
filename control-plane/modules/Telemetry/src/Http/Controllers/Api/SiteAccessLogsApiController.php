<?php

namespace Falak\Telemetry\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Contracts\AccessLogs;
use Falak\Telemetry\Contracts\Data\AccessLogEntry;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;

/**
 * GET /api/v1/sites/{site}/access-logs?since=&limit=&cursor=&server=&deployment=&method=&status=&path=&client_ip=
 * — the site's edge HTTP access log, newest first; `meta.cursor` pages to older requests.
 */
final class SiteAccessLogsApiController extends Controller
{
    public function __invoke(Request $request, string $site, CurrentOrganization $organization, OrganizationAccess $access, SiteDirectory $sites, AccessLogs $logs): JsonResponse
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
            'cursor' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
            'server' => ['nullable', 'string', 'max:26'],
            'deployment' => ['nullable', 'string', 'max:26'],
            'method' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,16}$/'],
            'status' => ['nullable', 'string', 'regex:/^([1-5]\d\d|[1-5]xx)$/i'],
            'path' => ['nullable', 'string', 'max:512'],
            'client_ip' => ['nullable', 'ip'],
        ]);

        $limit = (int) ($input['limit'] ?? 100);
        $end = now();
        $start = $end->copy()->subSeconds((int) ($input['since'] ?? 3600));

        if (! empty($input['cursor'])) {
            $end = now()->setTimestamp(intdiv((int) $input['cursor'], 1_000_000_000))->setMicrosecond(intdiv((int) $input['cursor'] % 1_000_000_000, 1000));
        }

        try {
            $entries = $start->lessThan($end) ? $logs->forSite($organizationId, $data->id, $start, $end, [
                'server_id' => $input['server'] ?? null,
                'deployment_id' => $input['deployment'] ?? null,
                'method' => $input['method'] ?? null,
                'status' => $input['status'] ?? null,
                'path' => $input['path'] ?? null,
                'client_ip' => $input['client_ip'] ?? null,
            ], $limit) : [];
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! empty($input['cursor'])) {
            $cursor = $input['cursor'];
            $entries = array_values(array_filter($entries, fn (AccessLogEntry $e) => strlen($e->timestampNs) < strlen($cursor) || (strlen($e->timestampNs) === strlen($cursor) && strcmp($e->timestampNs, $cursor) < 0)));
        }

        $last = $entries === [] ? null : $entries[array_key_last($entries)];

        return response()->json([
            'data' => array_map(fn (AccessLogEntry $e) => $e->toArray(), $entries),
            'meta' => ['cursor' => count($entries) >= $limit && $last ? $last->timestampNs : ''],
        ]);
    }
}
