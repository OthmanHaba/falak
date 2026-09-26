<?php

namespace Kiln\Builds\Application;

use Kiln\Builds\Application\Artifacts\ArtifactStorage;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\SourceControl\Contracts\SourceControlGateway;
use RuntimeException;

/**
 * The kiln-builder job (agent/internal/builder/job.go `Job`) for an assigned build. Clone
 * credentials come from SourceControl at hand-out time and are never persisted.
 */
final class JobPayload
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly SourceControlGateway $sourceControl,
        private readonly ArtifactStorage $storage,
        private readonly BuildConfiguration $configuration,
        private readonly Registry $registry,
    ) {}

    public static function artifactKey(Build $build): string
    {
        return strtolower("{$build->organization_id}/{$build->site_id}/{$build->id}.tar.gz");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the site or its repository credentials are unavailable
     */
    public function for(Build $build): array
    {
        $site = $this->sites->find($build->site_id) ?? throw new RuntimeException('The site no longer exists.');

        if ($site->sourceConnectionId === null || $site->repository === null) {
            throw new RuntimeException('The site has no repository.');
        }

        $credentials = $this->sourceControl->checkoutCredentials($site->sourceConnectionId, $site->repository, $site->deployKeyId);

        $repo = array_filter([
            'url' => $credentials->url,
            'ref' => $build->branch,
            'commit' => $build->commit,
            'deploy_key' => $credentials->sshPrivateKey,
            'known_hosts' => $credentials->knownHosts,
            'token' => $credentials->usesSsh() ? null : $credentials->httpsPassword,
            'username' => $credentials->usesSsh() ? null : $credentials->httpsUsername,
        ], fn ($value) => $value !== null && $value !== '');

        $job = [
            'id' => $build->id,
            'mode' => $build->mode,
            'repo' => $repo,
            'timeout_s' => $build->timeout_s,
        ];

        if (($hint = BuildConfiguration::runtimeHint($site)) !== null && $build->mode === 'native') {
            $job['runtime'] = $hint;
        }

        $env = $this->configuration->environment($site);

        if ($env !== []) {
            $job['env'] = (object) $env;
        }

        if ($build->mode === 'docker') {
            $docker = array_filter([
                'image' => $this->registry->image($site->slug, $build->id),
                'dockerfile' => $site->dockerfile,
                'build_args' => $env === [] ? null : (object) $env,
                'registry' => $this->registry->auth(),
                'push' => true,
            ], fn ($value) => $value !== null && $value !== '');
            $job['docker'] = $docker;
        } else {
            $key = $build->artifact_key ?? self::artifactKey($build);
            $upload = $this->storage->uploadTarget($key, max((int) config('builds.artifacts.upload_ttl', 3600), $build->timeout_s + 600));
            $job['native'] = ['upload' => array_filter(['url' => $upload['url'], 'headers' => $upload['headers'] === [] ? null : (object) $upload['headers']])];
        }

        return $job;
    }
}
