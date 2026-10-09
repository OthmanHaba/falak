<?php

namespace Falak\Fleet\Http\Controllers\Agent;

use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\AgentRequests;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /agent/v1/requests/{type} — a request an agent sends to the control plane (contracts/agent-protocol/requests),
 * answered by the module that registered the type (AgentRequests). The body is validated against
 * requests/<type>.schema.json; 404 `unknown_request` for a type nobody answers, 409 with a reason code when the
 * handler refuses, 422 when the body is invalid.
 */
final class RequestController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request, string $type, AgentRequests $requests, ProtocolSchemas $schemas): JsonResponse
    {
        $class = $requests->handler($type);
        $agent = $this->agent($request);

        if ($class === null) {
            return response()->json(['message' => "Unknown agent request [{$type}].", 'error' => 'unknown_request'], 404);
        }

        if ($agent->server_id === null) {
            return response()->json(['message' => 'This agent has no server.', 'error' => 'no_server'], 409);
        }

        $schema = "requests/{$type}.schema.json";
        $body = $this->document($request, $schemas, is_file($schemas->path().'/'.$schema) ? $schema : null);

        /** @var AgentRequestHandler $handler */
        $handler = app($class);

        try {
            return response()->json($handler->handle(new AgentCaller($agent->id, $agent->organization_id, (string) $agent->server_id), $body));
        } catch (AgentRequestRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'error' => $refused->reason], 409);
        }
    }
}
