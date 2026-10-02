<?php

namespace Kiln\Fleet\Http\Controllers\Agent;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Kernel\Http\Controller;

/**
 * GET /agent/v1/ping — an authenticated no-op for `kiln-agent check`: { agent_id, time }. Changes nothing (no
 * heartbeat, no session), so it can run next to the agent; `time` lets the agent check its clock.
 */
final class PingController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['agent_id' => $this->agent($request)->id, 'time' => now()->toIso8601String()]);
    }
}
