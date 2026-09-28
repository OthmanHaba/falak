<?php

namespace Kiln\SourceControl\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Contracts\ProviderType;
use Kiln\SourceControl\Domain\Models\Connection;
use Kiln\SourceControl\Infrastructure\GitHubApp\AppCredentials;
use Kiln\SourceControl\Infrastructure\Providers\GitHubAppTokens;
use Kiln\SourceControl\Infrastructure\Providers\GitHubClient;

/**
 * Turn a GitHub App installation into a connection of the organization, after proving with the app's JWT that
 * the installation belongs to the app (installation ids alone are guessable). A reinstall on the same account
 * revives the organization's disconnected connection, so sites that used it keep working.
 */
final class ConnectGitHubInstallation
{
    public function __construct(
        private readonly GitHubAppTokens $tokens,
        private readonly CreateConnection $create,
        private readonly GitHubClient $github,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws SourceControlException
     */
    public function __invoke(AppCredentials $app, string $organizationId, ?string $userId, string $installationId): Connection
    {
        $installation = $this->tokens->installation($app, $installationId);
        $account = $installation['account'] ?: null;
        $status = $installation['suspended'] ? 'suspended' : 'active';

        $sameApp = fn ($query) => $app->fromEnv()
            ? $query->where(fn ($q) => $q->where('github_app_id', AppCredentials::ENV)->orWhereNull('github_app_id'))
            : $query->where('github_app_id', $app->key);

        $claimed = Connection::query()->where('auth_type', 'app')->where('installation_id', $installationId)->tap($sameApp)->first();

        if ($claimed && $claimed->organization_id !== $organizationId) {
            throw new SourceControlException('This GitHub App installation is already connected to another organization.');
        }

        $connection = $claimed ?? Connection::query()
            ->where('organization_id', $organizationId)
            ->where('auth_type', 'app')
            ->where('status', 'disconnected')
            ->where('account', $account)
            ->tap($sameApp)
            ->first();

        if ($connection) {
            $connection->forceFill(['installation_id' => $installationId, 'status' => $status, 'account' => $account ?? $connection->account]);
            $connection->mergeCredentials(['installation_id' => $installationId, 'target_type' => $installation['target_type']]);

            $this->audit->record('source_control.installation_updated', 'source_control_connection', $connection->id, ['installation_id' => $installationId], $organizationId);
        } else {
            $connection = ($this->create)(
                $organizationId,
                $userId,
                ProviderType::GitHub,
                'app',
                ['installation_id' => $installationId, 'target_type' => $installation['target_type']],
                name: 'GitHub ('.($account ?? $installationId).')',
                account: $account ?? $installationId,
            );
            $connection->forceFill(['github_app_id' => $app->key, 'installation_id' => $installationId, 'status' => $status])->save();
        }

        $this->tokens->forget($app, $installationId);
        GitHubClient::forgetRepositories($connection->id);

        if ($status === 'active') {
            try {
                $this->github->installationRepositories($connection, fresh: true);
            } catch (SourceControlException) {
                // Listed on demand later.
            }
        }

        return $connection;
    }
}
