<?php

namespace Falak\Fleet\Http\Controllers\Agent;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Falak\Fleet\Application\Actions\RecordHeartbeat;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Kernel\Http\Controller;

/**
 * POST /agent/v1/heartbeat — heartbeat.schema.json → 204.
 */
final class HeartbeatController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request, ProtocolSchemas $schemas, RecordHeartbeat $record): Response
    {
        $record($this->agent($request), $this->document($request, $schemas, 'heartbeat.schema.json'), $request->ip(), $this->session($request));

        return response()->noContent();
    }
}
