<?php

namespace Kiln\Sites\Contracts\Data;

use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;

final readonly class SiteData
{
    /**
     * @param  string  $slug  directory name under /srv/kiln/sites and FPM pool name (^[a-z0-9][a-z0-9-]{0,62}$)
     * @param  string  $rootPath  /srv/kiln/sites/<slug>
     * @param  string  $webDirectory  document root relative to the release ("public", "" for the release root)
     * @param  ?int  $appPort  local port the app listens on (node/bun/deno/docker/compose)
     * @param  ?string  $testDomain  <slug>.<KILN_TEST_DOMAIN> when enabled
     * @param  list<SharedPath>  $sharedPaths
     * @param  list<SiteTargetData>  $targets
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
        public array $sharedPaths,
        public array $targets,
    ) {}

    public function currentPath(): string
    {
        return $this->rootPath.'/current';
    }

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
        return $this->runtime === SiteRuntime::PhpFpm && $this->phpVersion ? "/run/php/kiln-{$this->slug}-{$this->phpVersion}.sock" : null;
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
}
