<?php

namespace Kiln\Fleet\Http\Controllers\Agent;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Kiln\Fleet\Events\InsightsReceived;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Kernel\Http\Controller;

/**
 * POST /agent/v1/insights — NDJSON insight summaries (contracts/telemetry) → 204.
 * Fleet authenticates and forwards; the Insights module consumes InsightsReceived.
 */
final class InsightsController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request, ProtocolSchemas $schemas): Response
    {
        $items = $this->ndjson($request, $schemas, null, 4 * 1024 * 1024, 5_000);

        foreach ($items as $index => $item) {
            if (! in_array($item['kind'] ?? null, ['exception', 'aggregate'], true)) {
                throw ValidationException::withMessages(["line.{$index}/kind" => 'kind must be "exception" or "aggregate".']);
            }
        }

        if ($items !== []) {
            $agent = $this->agent($request);
            InsightsReceived::dispatch($agent->id, $agent->organization_id, $agent->server_id, $items);
        }

        return response()->noContent();
    }
}
