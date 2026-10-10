<?php

namespace Falak\SourceControl\Http\Controllers;

use Falak\Kernel\Http\Controller;
use Falak\SourceControl\Application\Actions\ReceiveWebhook;
use Falak\SourceControl\Domain\Models\Webhook;
use Falak\SourceControl\Infrastructure\Webhooks\WebhookPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound push and pull request webhooks: POST /api/webhooks/source-control/{webhook}.
 */
final class WebhookController extends Controller
{
    public function __invoke(Request $request, string $webhook, WebhookPayloads $payloads, ReceiveWebhook $receive): JsonResponse
    {
        $model = Webhook::query()->with('connection')->find($webhook);

        if (! $model || ! $model->connection) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        $provider = $model->connection->provider;

        if (! $payloads->verify($provider, $request, $model->secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        if ($payloads->isPing($provider, $request)) {
            $model->forceFill(['last_delivery_at' => now()])->save();

            return response()->json(['ok' => true]);
        }

        $pushes = $receive($model, $request);

        return response()->json(['received' => count($pushes), 'pull_request_events' => $receive->pullRequestEvents], 202);
    }
}
