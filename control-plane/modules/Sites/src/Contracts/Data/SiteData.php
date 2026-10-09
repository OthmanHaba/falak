<?php

namespace Falak\Sites\Contracts\Data;

use Falak\Limits\Contracts\LimitDefaults;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetStatus;

final readonly class SiteData
{
    /**
     * @param  string  $slug  directory name under /srv/falak/sites and FPM pool name (^[a-z0-9][a-z0-9-]{0,62}$)
     * @param  string  $rootPath  /srv/falak/sites/<slug>
     * @param  string  $webDirectory  document root relative to the release ("public", "" for the release root)
     * @param  ?int  $appPort  loopback host port Caddy proxies to (node/bun/deno listen on it; docker/compose publish to it)
     * @param  ?string  $testDomain  <slug>.<FALAK_TEST_DOMAIN> when enabled
     * @param  list<SiteTargetData>  $targets
     * @param  ?ComposeConfig  $compose  compose runtime only
     * @param  ?int  $containerPort  docker runtime: the port the app listens on inside its container
     * @param  ?string  $rootDirectory  repository subfolder the app lives in (monorepos; null = the repository root)
     * @param  ResourceLimits  $limits  the site's own limits (its container, or its slice on hosts); environment defaults
     *                                  apply on top ({@see LimitDefaults})
     * @param  array<string, ResourceLimits>  $composeLimits  compose runtime: the own limits of each service by name
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public string $slug,
        public SiteRuntime $runtime,
        public BuildMode $buildMode,
        public Framework $framework,
        public ?string $phpVersion,
        public ?string $nodeVersion,
        public ?string $sourceConnectionId,
        public ?string $repository,
        public ?string $branch,
        public ?string $deployKeyId,
        public bool $pushToDeploy,
        public string $rootPath,
        public string $webDirectory,
        public string $unixUser,
        public bool $isolated,
        public ?int $appPort,
        public ?string $dockerImage,
        public ?string $dockerfile,
        public ?string $composeFile,
        public ?string $healthCheckPath,
        public string $deployScript,
        public LaravelSettings $laravel,
        public ?string $testDomain,
        public array $targets,
        public ?ComposeConfig $compose = null,
        public ?int $containerPort = null,
        public ?string $rootDirectory = null,
        public ResourceLimits $limits = new ResourceLimits,
        public array $composeLimits = [],
    ) {}

    /** Docker runtime: the in-container port (sites from before container_port listen on their host port). */
    public function listenPort(): ?int
    {
        return $this->containerPort ?? $this->appPort;
    }

    /** Something to deploy: a repository, a docker image, an inline compose file, or a function's code. */
    public function hasDeploySource(): bool
    {
        return $this->repository !== null
            || $this->runtime === SiteRuntime::Function
            || ($this->runtime === SiteRuntime::Docker && $this->dockerImage !== null)
            || ($this->compose?->source === ComposeSource::Inline && $this->compose->version !== null);
    }

    public function currentPath(): string
    {
        return $this->rootPath.'/current';
    }

    /** Where shared paths live on every server (Volumes: shared_path volumes). */
    public function sharedPath(): string
    {
        return $this->rootPath.'/shared';
    }

    /** Absolute document root through the `current` symlink. */
    public function documentRoot(): string
    {
        return rtrim($this->currentPath().'/'.trim($this->webDirectory, '/'), '/');
    }

    /** PHP-FPM pool socket on each target (php-fpm runtime only). */
    public function fpmSocket(): ?string
    {
        return $this->runtime === SiteRuntime::PhpFpm && $this->phpVersion ? "/run/php/falak-{$this->slug}-{$this->phpVersion}.sock" : null;
    }

    /** PHP CLI binary on the site's servers (e.g. "php8.4"); plain "php" for non-PHP runtimes. */
    public function phpBinary(): string
    {
        return $this->runtime->isPhp() && $this->phpVersion ? "php{$this->phpVersion}" : 'php';
    }

    public function target(string $serverId): ?SiteTargetData
    {
        foreach ($this->targets as $target) {
            if ($target->serverId === $serverId) {
                return $target;
            }
        }

        return null;
    }

    public function leader(): ?SiteTargetData
    {
        foreach ($this->targets as $target) {
            if ($target->isLeader()) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function serverIds(): array
    {
        return array_map(fn (SiteTargetData $target) => $target->serverId, $this->targets);
    }

    /**
     * Servers whose target is ready (the site user and directories exist).
     *
     * @return list<string>
     */
    public function readyServerIds(): array
    {
        return array_values(array_map(
            fn (SiteTargetData $target) => $target->serverId,
            array_filter($this->targets, fn (SiteTargetData $target) => $target->status === TargetStatus::Ready),
        ));
    }
}
