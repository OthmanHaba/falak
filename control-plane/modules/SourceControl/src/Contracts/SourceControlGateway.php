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

    /** Largest file {@see file()} returns. */
    public const MAX_FILE_BYTES = 1048576;

    /** Most paths {@see tree()} returns. */
    public const MAX_TREE_PATHS = 2000;

    /**
     * Content of a file at a ref (branch, tag or commit); null when the path doesn't exist or isn't a file.
     * Throws {@see Exceptions\NoApi} for git servers without an API and {@see SourceControlException} for files
     * larger than {@see MAX_FILE_BYTES}.
     */
    public function file(string $connectionId, string $repository, string $ref, string $path): ?string;

    /**
     * Whether a file or a directory exists at a ref (cheaper than {@see tree()} for large repositories).
     * Throws {@see Exceptions\NoApi} for git servers without an API.
     */
    public function exists(string $connectionId, string $repository, string $ref, string $path): bool;

    /**
     * Paths of the files at a ref matching a glob (`*` within a segment, `**` across segments; matched against the
     * whole path, or against the file name when the glob has no `/`), sorted, at most {@see MAX_TREE_PATHS}.
     * Throws {@see Exceptions\NoApi} for git servers without an API.
     *
     * @return list<string>
     */
    public function tree(string $connectionId, string $repository, string $ref, string $glob = '*'): array;

    /** SSH clone URL for the repository. */
    public function cloneUrl(string $connectionId, string $repository): string;

    /**
     * Clone URL + credentials for builders. Prefers the deploy key (SSH) when given; otherwise uses the
     * connection's token over HTTPS.
     */
    public function checkoutCredentials(string $connectionId, string $repository, ?string $deployKeyId = null): CheckoutCredentials;
}
