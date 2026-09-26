<?php

namespace Kiln\Sites\Domain\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Data\LaravelSettings;
use Kiln\Sites\Contracts\Data\SharedPath;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetRole;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $slug
 * @property SiteRuntime $runtime
 * @property BuildMode $build_mode
 * @property Framework $framework
 * @property ?string $php_version
 * @property ?string $node_version
 * @property ?string $source_connection_id
 * @property ?string $repository
 * @property ?string $branch
 * @property ?string $deploy_key_id
 * @property bool $push_to_deploy
 * @property string $web_directory
 * @property string $unix_user
 * @property bool $isolated
 * @property ?int $app_port
 * @property ?string $docker_image
 * @property ?string $dockerfile
 * @property ?string $compose_file
 * @property ?string $health_check_path
 * @property string $deploy_script
 * @property LaravelSettings $laravel
 * @property list<SharedPath> $shared_paths
 * @property bool $test_domain_enabled
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Site extends Model
{
    use HasUlids;

    protected $table = 'sites_sites';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'runtime' => SiteRuntime::class,
            'build_mode' => BuildMode::class,
            'framework' => Framework::class,
            'push_to_deploy' => 'boolean',
            'isolated' => 'boolean',
            'app_port' => 'integer',
            'test_domain_enabled' => 'boolean',
        ];
    }

    /**
     * @return Attribute<LaravelSettings, LaravelSettings|array<string, bool>>
     */
    protected function laravel(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => LaravelSettings::fromArray($value ? (array) json_decode($value, true) : []),
            set: fn (LaravelSettings|array $value) => json_encode($value instanceof LaravelSettings ? $value->toArray() : LaravelSettings::fromArray($value)->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return Attribute<list<SharedPath>, list<SharedPath|array{path: string, type?: string}>>
     */
    protected function sharedPaths(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => array_map(
                fn (array $path) => new SharedPath((string) $path['path'], (string) ($path['type'] ?? 'directory')),
                array_values(array_filter((array) json_decode($value ?? '[]', true), 'is_array')),
            ),
            set: fn (array $value) => json_encode(array_map(
                fn (SharedPath|array $path) => $path instanceof SharedPath ? $path->toArray() : (new SharedPath((string) $path['path'], (string) ($path['type'] ?? 'directory')))->toArray(),
                array_values($value),
            ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return HasMany<SiteTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(SiteTarget::class)->orderByRaw("case when role = 'leader' then 0 else 1 end")->orderBy('created_at');
    }

    /**
     * @return HasMany<EnvironmentVersion, $this>
     */
    public function environmentVersions(): HasMany
    {
        return $this->hasMany(EnvironmentVersion::class)->orderByDesc('version');
    }

    /**
     * @return HasOne<EnvironmentVersion, $this>
     */
    public function latestEnvironment(): HasOne
    {
        return $this->hasOne(EnvironmentVersion::class)->ofMany('version', 'max');
    }

    /**
     * @return HasMany<SiteCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(SiteCommand::class)->latest()->orderByDesc('id');
    }

    public function rootPath(): string
    {
        return rtrim((string) config('sites.root', '/srv/kiln/sites'), '/').'/'.$this->slug;
    }

    public function currentPath(): string
    {
        return $this->rootPath().'/current';
    }

    public function testDomain(): ?string
    {
        $base = config('sites.test_domain');

        return $this->test_domain_enabled && is_string($base) && $base !== '' ? $this->slug.'.'.strtolower(trim($base, '.')) : null;
    }

    public function leaderTarget(): ?SiteTarget
    {
        return $this->targets->firstWhere('role', TargetRole::Leader);
    }

    /** PHP CLI invocation on the site's servers. */
    public function phpBinary(): string
    {
        if (! $this->runtime->isPhp()) {
            return 'php';
        }

        return $this->php_version ? "php{$this->php_version}" : 'php';
    }

    /**
     * @return list<string>
     */
    public function serverIds(): array
    {
        return $this->targets->pluck('server_id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function toData(): SiteData
    {
        return new SiteData(
            id: $this->id,
            organizationId: $this->organization_id,
            name: $this->name,
            slug: $this->slug,
            runtime: $this->runtime,
            buildMode: $this->build_mode,
            framework: $this->framework,
            phpVersion: $this->php_version,
            nodeVersion: $this->node_version,
            sourceConnectionId: $this->source_connection_id,
            repository: $this->repository,
            branch: $this->branch,
            deployKeyId: $this->deploy_key_id,
            pushToDeploy: $this->push_to_deploy,
            rootPath: $this->rootPath(),
            webDirectory: $this->web_directory,
            unixUser: $this->unix_user,
            isolated: $this->isolated,
            appPort: $this->app_port,
            dockerImage: $this->docker_image,
            dockerfile: $this->dockerfile,
            composeFile: $this->compose_file,
            healthCheckPath: $this->health_check_path,
            deployScript: $this->deploy_script,
            laravel: $this->laravel,
            testDomain: $this->testDomain(),
            sharedPaths: $this->shared_paths,
            targets: $this->targets->map(fn (SiteTarget $target) => $target->toData())->values()->all(),
        );
    }
}
