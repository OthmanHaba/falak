<?php

namespace Falak\Fleet\Http\Controllers\Agent;

use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /agent/v1/ping — an authenticated request for `falak-agent check`: { agent_id, time }. It records no heartbeat
 * and no session, so it can run next to the agent (AuthenticateAgent still marks a certificate's first use and retires
 * the ones it superseded); `time` lets the agent check its clock.
 */
final class PingController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['agent_id' => $this->agent($request)->id, 'time' => now()->toIso8601String()]);
    }
}
