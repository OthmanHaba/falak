<?php

namespace Kiln\Sites\Application\Actions;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Application\SiteRules;
use Kiln\Sites\Application\SourceControlLinker;
use Kiln\Sites\Application\TargetProvisioner;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Data\SitePlacement;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\EnvironmentVersion;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\Sites\Domain\Presets\Preset;
use Kiln\Sites\Events\SiteCreated;

final class CreateSite
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /** @var list<string> warnings from the source control provider */
    public array $warnings = [];

    public function __construct(
        private readonly SiteRules $rules,
        private readonly TargetProvisioner $provisioner,
        private readonly SourceControlLinker $sourceControl,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated StoreSiteRequest input
     * @param  ?SitePlacement  $placement  passed through to SiteCreated for Projects
     * @param  bool  $requireServers  false lets a site start without servers (environment duplicates)
     * @param  ?Closure(Site): void  $configure  runs inside the creation transaction, before any side effect
     */
    public function __invoke(string $organizationId, ?string $userId, array $data, ?SitePlacement $placement = null, bool $requireServers = true, ?Closure $configure = null): Site
    {
        $framework = Framework::from((string) $data['framework']);
        $preset = Preset::for($framework);
        $runtime = isset($data['runtime']) ? SiteRuntime::from((string) $data['runtime']) : $preset->defaultRuntime();
        $buildMode = isset($data['build_mode']) ? BuildMode::from((string) $data['build_mode']) : $preset->defaultBuildMode($runtime);
        $phpVersion = $runtime->isPhp() ? (string) ($data['php_version'] ?? '') : null;

        if ($runtime->isPhp() && $phpVersion === '') {
            throw ValidationException::withMessages(['php_version' => 'Pick a PHP version.']);
        }

        /** @var list<string> $serverIds */
        $serverIds = array_values(array_unique(array_map('strval', (array) ($data['server_ids'] ?? []))));
        $leaderId = (string) ($data['leader_server_id'] ?? $serverIds[0] ?? '');

        if ($serverIds !== [] && ! in_array($leaderId, $serverIds, true)) {
            throw ValidationException::withMessages(['leader_server_id' => 'The leader must be one of the selected servers.']);
        }

        $this->rules->runtimeAndFramework($framework, $runtime, $buildMode);
        if ($requireServers || $serverIds !== []) {
            $this->rules->targets($organizationId, $serverIds, $runtime, $phpVersion, $buildMode);
        }

        $this->rules->connection($organizationId, $data['source_connection_id'] ?? null);

        $appPort = null;

        if ($runtime->proxiesToPort()) {
            $appPort = isset($data['app_port']) ? (int) $data['app_port'] : $this->rules->freePort($serverIds);
            $this->rules->portAvailable($appPort, $serverIds);
        }

        $slug = $this->slug((string) ($data['slug'] ?? '') ?: (string) $data['name']);
        $isolated = (bool) ($data['isolated'] ?? false);

        $site = DB::transaction(function () use ($organizationId, $userId, $data, $framework, $preset, $runtime, $buildMode, $phpVersion, $serverIds, $leaderId, $appPort, $slug, $isolated, $configure) {
            $site = Site::query()->create([
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'slug' => $slug,
                'runtime' => $runtime,
                'build_mode' => $buildMode,
                'framework' => $framework,
                'php_version' => $phpVersion,
                'node_version' => $runtime->isPhp() || $runtime->isContainer() ? ($data['node_version'] ?? null) : ($data['node_version'] ?? (string) config('sites.default_node', '22')),
                'source_connection_id' => $data['source_connection_id'] ?? null,
                'repository' => $data['repository'] ?? null,
                'branch' => $data['branch'] ?? null,
                'push_to_deploy' => (bool) ($data['push_to_deploy'] ?? false),
                'web_directory' => trim((string) ($data['web_directory'] ?? $preset->webDirectory), '/'),
                'unix_user' => $isolated ? $this->unixUser($slug) : (string) config('sites.unix_user', 'kiln'),
                'isolated' => $isolated,
                'app_port' => $appPort,
                'docker_image' => $data['docker_image'] ?? null,
                'dockerfile' => $runtime === SiteRuntime::Docker ? ($data['dockerfile'] ?? (isset($data['docker_image']) ? null : 'Dockerfile')) : null,
                'compose_file' => $runtime === SiteRuntime::Compose ? ($data['compose_file'] ?? 'compose.yaml') : null,
                'health_check_path' => $data['health_check_path'] ?? $preset->healthCheckPath,
                'deploy_script' => $preset->deployScript."\n",
                'laravel' => $framework->isLaravel() ? $preset->laravel : [],
                'shared_paths' => $preset->sharedPaths,
                'test_domain_enabled' => (bool) ($data['test_domain_enabled'] ?? true),
                'created_by' => $userId,
            ]);

            foreach ($serverIds as $serverId) {
                SiteTarget::query()->create([
                    'site_id' => $site->id,
                    'server_id' => $serverId,
                    'role' => $serverId === $leaderId ? TargetRole::Leader : TargetRole::Member,
                    'status' => TargetStatus::Pending,
                ]);
            }

            EnvironmentVersion::query()->create([
                'site_id' => $site->id,
                'version' => 1,
                'variables' => $this->initialEnvironment($site, $preset),
                'exposed' => [],
                'changed_keys' => [],
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            if ($configure !== null) {
                $configure($site);
            }

            return $site;
        });

        $site->load('targets');
        $this->warnings = $this->sourceControl->link($site);

        foreach ($site->targets as $target) {
            $target->setRelation('site', $site);
            $this->provisioner->start($target);
        }

        $this->audit->record('site.created', 'site', $site->id, [
            'name' => $site->name,
            'runtime' => $runtime->value,
            'framework' => $framework->value,
            'servers' => $serverIds,
            'repository' => $site->repository,
        ], $organizationId);

        SiteCreated::dispatch($site->id, $organizationId, $site->slug, $runtime->value, $serverIds, $placement);

        return $site->refresh()->load('targets');
    }

    private function slug(string $source): string
    {
        $base = Str::limit(trim(Str::slug($source), '-'), 50, '') ?: 'site';
        $base = preg_match(self::SLUG_PATTERN, $base) === 1 ? $base : 'site-'.$base;
        $slug = $base;

        for ($i = 2; Site::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * Linux user for an isolated site: ^[a-z_][a-z0-9_-]{0,31}$, unique among sites.
     */
    private function unixUser(string $slug): string
    {
        $base = substr(ctype_digit($slug[0]) ? "s{$slug}" : $slug, 0, 28);
        $user = rtrim($base, '-');

        for ($i = 2; Site::query()->where('unix_user', $user)->exists(); $i++) {
            $user = rtrim($base, '-')."-{$i}";
        }

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function initialEnvironment(Site $site, Preset $preset): array
    {
        $variables = $preset->environment;

        if (array_key_exists('APP_NAME', $variables)) {
            $variables['APP_NAME'] = $site->name;
        }

        if (array_key_exists('APP_KEY', $variables)) {
            $variables['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        }

        if (array_key_exists('APP_SECRET', $variables)) {
            $variables['APP_SECRET'] = bin2hex(random_bytes(16));
        }

        if (array_key_exists('APP_URL', $variables)) {
            $variables['APP_URL'] = ($domain = $site->testDomain()) ? "https://{$domain}" : '';
        }

        if ($site->app_port !== null) {
            $variables['PORT'] = (string) $site->app_port;
        }

        return $variables;
    }
}
