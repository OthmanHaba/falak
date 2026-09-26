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
 * Kinds: exception, aggregate, cron_heartbeat (validated against cron.apply $defs.heartbeat).
 * Fleet authenticates and forwards; the Insights module consumes InsightsReceived.
 */
final class InsightsController extends Controller
{
    use ReadsProtocolDocuments;

    public const KINDS = ['exception', 'aggregate', 'cron_heartbeat'];

    private const HEARTBEAT_SCHEMA = 'commands/cron.apply.schema.json#/$defs/heartbeat';

    public function __invoke(Request $request, ProtocolSchemas $schemas): Response
    {
        $items = $this->ndjson($request, $schemas, null, 4 * 1024 * 1024, 5_000, $raw);

        foreach ($items as $index => $item) {
            $kind = $item['kind'] ?? null;

            if (! in_array($kind, self::KINDS, true)) {
                throw ValidationException::withMessages(["line.{$index}/kind" => 'kind must be one of: '.implode(', ', self::KINDS).'.']);
            }

            if ($kind === 'cron_heartbeat') {
                foreach ($schemas->validate(self::HEARTBEAT_SCHEMA, $raw[$index]) as $pointer => $messages) {
                    throw ValidationException::withMessages(["line.{$index}{$pointer}" => $messages]);
                }
            }
        }

        if ($items !== []) {
            $agent = $this->agent($request);
            InsightsReceived::dispatch($agent->id, $agent->organization_id, $agent->server_id, $items);
        }

        return response()->noContent();
    }
}
