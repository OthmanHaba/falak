<?php

namespace Kiln\SourceControl\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Kernel\Http\Controller;
use Kiln\SourceControl\Application\Actions\ReceiveGitHubAppEvent;
use Kiln\SourceControl\Contracts\ProviderType;
use Kiln\SourceControl\Domain\Models\GitHubApp;
use Kiln\SourceControl\Infrastructure\GitHubApp\GitHubAppResolver;
use Kiln\SourceControl\Infrastructure\Webhooks\WebhookPayloads;

/**
 * A GitHub App's webhook: POST /api/webhooks/source-control/github-app/{app}, where {app} is the registered app's
 * id or "env" for the operator-configured one. Verified with X-Hub-Signature-256 and the app's webhook secret.
 */
final class GitHubAppWebhookController extends Controller
{
    public function __invoke(Request $request, string $app, GitHubAppResolver $apps, WebhookPayloads $payloads, ReceiveGitHubAppEvent $receive): JsonResponse
    {
        $credentials = $apps->find($app);

        if (! $credentials || ! $credentials->webhookSecret) {
            return response()->json(['message' => 'Unknown app.'], 404);
        }

        if (! $payloads->verify(ProviderType::GitHub, $request, $credentials->webhookSecret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        if (! $credentials->fromEnv()) {
            GitHubApp::query()->whereKey($credentials->key)->update(['last_delivery_at' => now()]);
        }

        if ($payloads->isPing(ProviderType::GitHub, $request)) {
            return response()->json(['ok' => true]);
        }

        return response()->json($receive($credentials, $request), 202);
    }
}
