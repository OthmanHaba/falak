<?php

namespace Kiln\SourceControl\Contracts;

use Kiln\SourceControl\Contracts\Data\BranchData;
use Kiln\SourceControl\Contracts\Data\CheckoutCredentials;
use Kiln\SourceControl\Contracts\Data\CommitData;
use Kiln\SourceControl\Contracts\Data\ConnectionData;
use Kiln\SourceControl\Contracts\Data\DeployKeyData;
use Kiln\SourceControl\Contracts\Data\RepositoryData;
use Kiln\SourceControl\Contracts\Data\WebhookData;
use Kiln\SourceControl\Contracts\Exceptions\ConnectionNotFound;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;

/**
 * Git provider access for other modules (Sites, Builds, Deployments).
 *
 * Every method taking a connection id throws {@see ConnectionNotFound} for unknown ids and
 * {@see SourceControlException} when the provider API fails.
 */
interface SourceControlGateway
{
    public function connection(string $connectionId): ?ConnectionData;

    /**
     * @return list<ConnectionData>
     */
    public function connections(string $organizationId): array;

    /**
     * Repositories the connection can access (empty for custom git).
     *
     * @return list<RepositoryData>
     */
    public function repositories(string $connectionId, ?string $search = null): array;

    public function repository(string $connectionId, string $repository): ?RepositoryData;

    /**
     * @return list<BranchData>
     */
    public function branches(string $connectionId, string $repository): array;

    /** Head commit of a branch (null for custom git or unknown branches). */
    public function latestCommit(string $connectionId, string $repository, string $branch): ?CommitData;

    public function commit(string $connectionId, string $repository, string $sha): ?CommitData;

    /**
     * Generate a fresh ed25519 deploy key for the repository and register it (read-only) at the
     * provider when it has an API. Registration failures are reported on the returned data, not thrown.
     */
    public function installDeployKey(string $connectionId, string $repository, string $title): DeployKeyData;

    public function deployKey(string $deployKeyId): ?DeployKeyData;

    /** Remove a deploy key locally and (best-effort) at the provider. */
    public function removeDeployKey(string $deployKeyId): void;

    /**
     * Ensure a push webhook for the repository exists (created at the provider when possible).
     * Idempotent: returns the existing webhook when one is registered.
     */
    public function ensureWebhook(string $connectionId, string $repository): WebhookData;

    /** Remove the push webhook when no longer needed (best-effort at the provider). */
    public function removeWebhook(string $connectionId, string $repository): void;

    /** SSH clone URL for the repository. */
    public function cloneUrl(string $connectionId, string $repository): string;

    /**
     * Clone URL + credentials for builders. Prefers the deploy key (SSH) when given; otherwise uses the
     * connection's token over HTTPS.
     */
    public function checkoutCredentials(string $connectionId, string $repository, ?string $deployKeyId = null): CheckoutCredentials;
}
