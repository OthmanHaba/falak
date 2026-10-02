<?php

namespace Kiln\SourceControl\Infrastructure\Providers;

use Kiln\SourceControl\Contracts\Data\RepositoryData;
use Kiln\SourceControl\Contracts\Exceptions\NoApi;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Domain\Models\Connection;

/**
 * Any git server reachable over SSH. "Repository" is the clone URL itself; there is no API, so deploy
 * keys and webhooks are configured manually by the user.
 */
class CustomGitClient implements ProviderClient
{
    public function account(Connection $connection): string
    {
        return (string) ($connection->account ?? '');
    }

    public function repositories(Connection $connection, ?string $search = null): array
    {
        return [];
    }

    public function repository(Connection $connection, string $repository): ?RepositoryData
    {
        return new RepositoryData(
            fullName: $repository,
            defaultBranch: 'main',
            private: true,
            sshUrl: $this->sshUrl($connection, $repository),
            httpsUrl: $this->httpsUrl($connection, $repository),
        );
    }

    public function branches(Connection $connection, string $repository): array
    {
        return [];
    }

    public function latestCommit(Connection $connection, string $repository, string $branch): null
    {
        return null;
    }

    public function commit(Connection $connection, string $repository, string $sha): null
    {
        return null;
    }

    public function file(Connection $connection, string $repository, string $ref, string $path, int $maxBytes): ?string
    {
        throw NoApi::forConnection((string) ($connection->name ?? 'This connection'));
    }

    public function exists(Connection $connection, string $repository, string $ref, string $path): bool
    {
        throw NoApi::forConnection((string) ($connection->name ?? 'This connection'));
    }

    public function tree(Connection $connection, string $repository, string $ref, int $limit): array
    {
        throw NoApi::forConnection((string) ($connection->name ?? 'This connection'));
    }

    public function addDeployKey(Connection $connection, string $repository, string $title, string $publicKey): string
    {
        throw new SourceControlException('Custom git servers have no API; add the deploy key manually.');
    }

    public function removeDeployKey(Connection $connection, string $repository, string $keyId): void {}

    public function createWebhook(Connection $connection, string $repository, string $url, string $secret): string
    {
        throw new SourceControlException('Custom git servers have no API; configure the webhook manually.');
    }

    public function deleteWebhook(Connection $connection, string $repository, string $hookId): void {}

    public function sshUrl(Connection $connection, string $repository): string
    {
        return self::isUrl($repository) ? $repository : rtrim((string) $connection->base_url, '/').'/'.ltrim($repository, '/');
    }

    public function httpsUrl(Connection $connection, string $repository): string
    {
        $url = $this->sshUrl($connection, $repository);

        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://') ? $url : '';
    }

    public function httpsCredentials(Connection $connection): array
    {
        return [null, null];
    }

    /** git@host:path, ssh://…, https://… */
    public static function isUrl(string $value): bool
    {
        return preg_match('#^(ssh|https?|git)://#', $value) === 1 || preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:.+#', $value) === 1;
    }
}
