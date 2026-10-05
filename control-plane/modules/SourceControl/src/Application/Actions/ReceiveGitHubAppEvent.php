<?php

namespace Falak\SourceControl\Application\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Falak\Identity\Contracts\AuditLog;
use Falak\SourceControl\Application\Jobs\RefreshInstallationRepositories;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\Webhook;
use Falak\SourceControl\Events\PushReceived;
use Falak\SourceControl\Infrastructure\GitHubApp\AppCredentials;
use Falak\SourceControl\Infrastructure\Providers\GitHubAppTokens;
use Falak\SourceControl\Infrastructure\Providers\GitHubClient;
use Falak\SourceControl\Infrastructure\Webhooks\WebhookPayloads;

/**
 * A verified delivery to a GitHub App's webhook (one per app, covering every installation and repository):
 *
 * - `push` → the push log + {@see PushReceived}, for repositories a Falak site deploys
 *   from (the ones {@see SourceControlGateway::ensureWebhook()} marked);
 * - `installation` deleted / suspend / unsuspend → the connection becomes disconnected / suspended / active;
 * - `installation_repositories` → the cached repository list is refreshed.
 */
final class ReceiveGitHubAppEvent
{
    public function __construct(
        private readonly WebhookPayloads $payloads,
        private readonly RecordPushes $record,
        private readonly GitHubAppTokens $tokens,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array<string, mixed> response summary
     */
    public function __invoke(AppCredentials $app, Request $request): array
    {
        $event = (string) $request->header('X-GitHub-Event');
        $payload = (array) $request->json()->all();
        $installationId = (string) ($payload['installation']['id'] ?? '');

        if ($installationId === '' || ! in_array($event, ['push', 'installation', 'installation_repositories'], true)) {
            return ['ignored' => $event];
        }

        $connections = Connection::query()
            ->where('auth_type', 'app')
            ->where('installation_id', $installationId)
            ->where(fn ($query) => $app->fromEnv()
                ? $query->where('github_app_id', AppCredentials::ENV)->orWhereNull('github_app_id')
                : $query->where('github_app_id', $app->key))
            ->get();

        return match ($event) {
            'push' => $this->push($connections, $payload, $request),
            'installation' => $this->installation($app, $connections, (string) ($payload['action'] ?? ''), $installationId),
            'installation_repositories' => $this->repositories($connections),
        };
    }

    /**
     * @param  Collection<int, Connection>  $connections
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function push(Collection $connections, array $payload, Request $request): array
    {
        $repository = (string) ($payload['repository']['full_name'] ?? '');
        $received = 0;

        foreach ($connections->where('status', 'active') as $connection) {
            $webhook = Webhook::query()
                ->where('connection_id', $connection->id)
                ->whereRaw('lower(repository) = ?', [strtolower($repository)])
                ->first();

            // Not a repository Falak deploys from (no push-to-deploy site): nothing to record.
            if ($repository === '' || ! $webhook) {
                continue;
            }

            $webhook->forceFill(['last_delivery_at' => now()])->save();
            $received += count(($this->record)($connection, $webhook->repository, $this->payloads->pushes(ProviderType::GitHub, $request), $webhook));
        }

        return ['received' => $received];
    }

    /**
     * @param  Collection<int, Connection>  $connections
     * @return array<string, mixed>
     */
    private function installation(AppCredentials $app, Collection $connections, string $action, string $installationId): array
    {
        $status = match ($action) {
            'deleted' => 'disconnected',
            'suspend' => 'suspended',
            'unsuspend' => 'active',
            default => null,
        };

        if (in_array($action, ['deleted', 'suspend', 'new_permissions_accepted'], true)) {
            $this->tokens->forget($app, $installationId);
        }

        foreach ($connections as $connection) {
            GitHubClient::forgetRepositories($connection->id);

            if ($status === null || $connection->status === $status) {
                continue;
            }

            $connection->forceFill(['status' => $status])->save();
            $this->audit->record('source_control.installation_'.$status, 'source_control_connection', $connection->id, [
                'installation_id' => $installationId,
                'action' => $action,
            ], $connection->organization_id);
        }

        return ['updated' => $status === null ? 0 : $connections->count()];
    }

    /**
     * @param  Collection<int, Connection>  $connections
     * @return array<string, mixed>
     */
    private function repositories(Collection $connections): array
    {
        foreach ($connections as $connection) {
            GitHubClient::forgetRepositories($connection->id);
            RefreshInstallationRepositories::dispatch($connection->id);
        }

        return ['refreshed' => $connections->count()];
    }
}
