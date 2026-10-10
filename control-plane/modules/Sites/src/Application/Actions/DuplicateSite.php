<?php

namespace Falak\Sites\Application\Actions;

use Falak\Sites\Application\OctanePorts;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\SecretVariables;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Contracts\VolumeMounts;
use Illuminate\Support\Str;

/**
 * Copy a site (configuration, deploy script, toggles, shared paths and variables) into a new site,
 * e.g. when Projects forks an environment. Servers are not copied unless given in the overrides.
 */
final class DuplicateSite
{
    /** Overrides callers may set; everything else is copied from the source. */
    public const OVERRIDES = ['name', 'name_suffix', 'branch', 'server_ids', 'leader_server_id', 'push_to_deploy', 'strip_secrets'];

    public function __construct(
        private readonly CreateSite $create,
        private readonly OctanePorts $octanePorts,
        private readonly ServiceVolumes $volumes,
        private readonly VolumeMounts $mounts,
        private readonly SecretVariables $secrets,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  see {@see self::OVERRIDES}
     */
    public function __invoke(Site $source, array $overrides = [], ?SitePlacement $placement = null, ?string $userId = null): Site
    {
        $overrides = array_intersect_key($overrides, array_flip(self::OVERRIDES));
        $source->loadMissing('latestEnvironment');
        $serverIds = array_values(array_map('strval', (array) ($overrides['server_ids'] ?? [])));

        $data = array_filter([
            'name' => (string) ($overrides['name'] ?? $this->copyName($source, (string) ($overrides['name_suffix'] ?? 'copy'))),
            'framework' => $source->framework->value,
            'runtime' => $source->runtime->value,
            'build_mode' => $source->build_mode->value,
            'php_version' => $source->php_version,
            'node_version' => $source->node_version,
            'source_connection_id' => $source->source_connection_id,
            'repository' => $source->repository,
            'branch' => $overrides['branch'] ?? $source->branch,
            'root_directory' => $source->root_directory,
            'push_to_deploy' => (bool) ($overrides['push_to_deploy'] ?? false),
            'web_directory' => $source->web_directory,
            // Without servers the port cannot collide; with servers a free one is picked.
            'app_port' => $serverIds === [] ? $source->app_port : null,
            'container_port' => $source->container_port,
            'docker_image' => $source->docker_image,
            'dockerfile' => $source->dockerfile,
            'compose_file' => $source->compose_file,
            'compose_source' => $source->compose_source?->value,
            'compose_content' => $source->compose_source === ComposeSource::Inline ? app(ComposeSites::class)->content($source->id)?->content : null,
            'public_services' => $source->public_services !== null
                ? array_map(fn (array $p) => ['service' => $p['service'], 'port' => $p['port'], 'domain' => null], array_values(array_filter($source->public_services, 'is_array')))
                : null,
            'template' => $source->template,
            'health_check_path' => $source->health_check_path,
            'test_domain_enabled' => $source->test_domain_enabled,
            'isolated' => $source->isolated,
            'server_ids' => $serverIds,
            'leader_server_id' => $overrides['leader_server_id'] ?? null,
        ], fn ($value) => $value !== null);

        return ($this->create)(
            $source->organization_id,
            $userId,
            $data,
            $placement,
            requireServers: false,
            configure: function (Site $copy) use ($source, $overrides) {
                $copy->forceFill([
                    'deploy_script' => $source->deploy_script,
                    'laravel' => $source->laravel,
                ])->save();
                $this->volumes->syncSharedPaths($copy->organization_id, $copy->id, $this->mounts->sharedPaths($source->id));

                // The copy may share servers with the source: it gets its own Octane port.
                $this->octanePorts->reassign($copy->load('targets'));

                $environment = $source->latestEnvironment;

                if ($environment === null) {
                    return;
                }

                // Still inside the creation transaction: version 1 is replaced before anything reads it.
                EnvironmentVersion::query()->where('site_id', $copy->id)->where('version', 1)->firstOrFail()->forceFill([
                    'variables' => $this->copyVariables($source, $copy, $environment->variables, (bool) ($overrides['strip_secrets'] ?? false)),
                    'exposed' => array_values($environment->exposed ?? []),
                ])->save();
            },
        );
    }

    /**
     * Variables are copied verbatim (references keep pointing at same-named services of the new
     * environment), except values derived from the source site itself. $stripSecrets (a fork's preview) empties the
     * secret values written in the variables; references stay, and resolving them refuses secrets there.
     *
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function copyVariables(Site $source, Site $copy, array $variables, bool $stripSecrets = false): array
    {
        $variables = array_map('strval', $variables);

        if ($stripSecrets) {
            foreach ($this->secrets->names($variables) as $name) {
                if (! str_contains($variables[$name], '${{')) {
                    $variables[$name] = '';
                }
            }
        }

        if (($from = $source->testDomain()) !== null && ($variables['APP_URL'] ?? null) === "https://{$from}") {
            $variables['APP_URL'] = ($to = $copy->testDomain()) !== null ? "https://{$to}" : '';
        }

        if ($copy->app_port !== null && array_key_exists('PORT', $variables)) {
            $variables['PORT'] = (string) ($copy->container_port ?? $copy->app_port);
        }

        return $variables;
    }

    /**
     * "<name>-<suffix>" (max 64 chars), unique within the organization.
     */
    private function copyName(Site $source, string $suffix): string
    {
        $suffix = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $suffix), '-') ?: 'copy';
        $base = Str::limit($source->name, 60 - strlen($suffix), '').'-'.$suffix;
        $name = $base;

        for ($i = 2; Site::query()->where('organization_id', $source->organization_id)->where('name', $name)->exists(); $i++) {
            $name = Str::limit($base, 60, '')."-{$i}";
        }

        return $name;
    }
}
