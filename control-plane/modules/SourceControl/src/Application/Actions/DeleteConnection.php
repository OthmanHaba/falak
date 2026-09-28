<?php

namespace Kiln\SourceControl\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Contracts\SourceControlGateway;
use Kiln\SourceControl\Domain\Models\Connection;
use Kiln\SourceControl\Domain\Models\DeployKey;
use Kiln\SourceControl\Domain\Models\Webhook;
use Kiln\SourceControl\Events\ConnectionDeleted;
use Kiln\SourceControl\Infrastructure\GitHubApp\GitHubAppResolver;
use Kiln\SourceControl\Infrastructure\Providers\GitHubAppTokens;
use Kiln\SourceControl\Infrastructure\Providers\GitHubClient;

/**
 * Disconnect: best-effort removal of webhooks and deploy keys at the provider (GitHub App connections: uninstall
 * the app from the account), then delete everything.
 */
final class DeleteConnection
{
    public function __construct(
        private readonly SourceControlGateway $gateway,
        private readonly AuditLog $audit,
        private readonly GitHubAppResolver $apps,
        private readonly GitHubAppTokens $tokens,
    ) {}

    public function __invoke(Connection $connection, bool $cleanUpProvider = true): void
    {
        if ($cleanUpProvider) {
            $connection->webhooks()->get()->each(fn (Webhook $webhook) => $this->gateway->removeWebhook($connection->id, $webhook->repository));
            $connection->deployKeys()->get()->each(fn (DeployKey $key) => $this->gateway->removeDeployKey($key->id));

            if ($connection->isApp() && $connection->status !== 'disconnected' && ($app = $this->apps->forConnection($connection))) {
                try {
                    $this->tokens->deleteInstallation($app, $connection->installationId());
                } catch (SourceControlException) {
                    // Best effort: the installation may already be gone.
                }
            }
        }

        if ($connection->isApp()) {
            GitHubClient::forgetRepositories($connection->id);
        }

        $connection->delete();

        $this->audit->record('source_control.disconnected', 'source_control_connection', $connection->id, [
            'provider' => $connection->provider->value,
            'name' => $connection->name,
        ], $connection->organization_id);

        ConnectionDeleted::dispatch($connection->id, $connection->organization_id, $connection->provider->value);
    }
}
