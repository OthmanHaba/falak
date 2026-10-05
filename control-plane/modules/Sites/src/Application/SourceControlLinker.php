<?php

namespace Falak\Sites\Application;

use Falak\Sites\Domain\Models\Site;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\SourceControlGateway;

/**
 * Keeps a site's deploy key and push webhook in sync with its repository. Provider failures never block
 * the site change; they come back as warnings for the UI.
 */
final class SourceControlLinker
{
    public function __construct(private readonly SourceControlGateway $gateway) {}

    /**
     * Generate + install a deploy key (and the webhook when push-to-deploy is on).
     *
     * @return list<string> warnings
     */
    public function link(Site $site): array
    {
        if (! $site->source_connection_id || ! $site->repository) {
            return [];
        }

        $warnings = [];

        // GitHub App connections clone with short-lived installation tokens: no deploy key to manage.
        if ($this->gateway->connection($site->source_connection_id)?->isGitHubApp()) {
            return $this->syncWebhook($site);
        }

        try {
            $key = $this->gateway->installDeployKey($site->source_connection_id, $site->repository, "Falak · {$site->name}");
            $site->forceFill(['deploy_key_id' => $key->id])->save();

            if (! $key->installed && $key->installError !== null) {
                $warnings[] = "The deploy key could not be added to {$site->repository}: {$key->installError}. Add it manually from the site overview.";
            }
        } catch (SourceControlException $e) {
            $warnings[] = "Could not create a deploy key: {$e->getMessage()}";
        }

        return [...$warnings, ...$this->syncWebhook($site)];
    }

    /**
     * @return list<string> warnings
     */
    public function syncWebhook(Site $site): array
    {
        if (! $site->source_connection_id || ! $site->repository || ! $site->push_to_deploy) {
            return [];
        }

        try {
            $webhook = $this->gateway->ensureWebhook($site->source_connection_id, $site->repository);

            if (! $webhook->installed && $webhook->installError !== null) {
                return ["The push webhook could not be created: {$webhook->installError}. Configure {$webhook->url} manually."];
            }
        } catch (SourceControlException $e) {
            return ["Could not create the push webhook: {$e->getMessage()}"];
        }

        return [];
    }

    /**
     * Remove the site's deploy key, and the repository webhook when no other push-to-deploy site uses it.
     */
    public function unlink(string $siteId, ?string $connectionId, ?string $repository, ?string $deployKeyId): void
    {
        try {
            if ($deployKeyId) {
                $this->gateway->removeDeployKey($deployKeyId);
            }

            if ($connectionId && $repository && ! $this->webhookStillNeeded($siteId, $connectionId, $repository)) {
                $this->gateway->removeWebhook($connectionId, $repository);
            }
        } catch (SourceControlException) {
            // Best-effort cleanup at the provider.
        }
    }

    public function webhookStillNeeded(string $exceptSiteId, string $connectionId, string $repository): bool
    {
        return Site::query()
            ->whereKeyNot($exceptSiteId)
            ->where('source_connection_id', $connectionId)
            ->whereRaw('lower(repository) = ?', [strtolower($repository)])
            ->where('push_to_deploy', true)
            ->exists();
    }
}
