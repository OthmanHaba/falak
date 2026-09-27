<?php

namespace Kiln\Builds\Application;

use Kiln\Projects\Contracts\VariableReferences;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * What a site's build depends on: mode, build-time environment and the cache key that identifies
 * equivalent builds (same site + commit + configuration ⇒ the artifact can be reused).
 */
final class BuildConfiguration
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly VariableReferences $references,
    ) {}

    public static function mode(SiteData $site): ?string
    {
        return match ($site->buildMode) {
            BuildMode::Native => 'native',
            BuildMode::Docker => 'docker',
            BuildMode::OnServer => null,
        };
    }

    /**
     * Build-time environment: site variables with a public front-end prefix (VITE_…, NEXT_PUBLIC_…), with
     * `${{ service.KEY }}` references resolved (unresolvable ones stay literal; the deploy fails on them).
     *
     * @return array<string, string>
     */
    public function environment(SiteData $site): array
    {
        $variables = $this->sites->environment($site->id)?->variables ?? [];
        $prefixes = (array) config('builds.env_prefixes', []);

        $public = array_filter($variables, function ($value, $key) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $key, (string) $prefix)) {
                    return true;
                }
            }

            return false;
        }, ARRAY_FILTER_USE_BOTH);

        if ($public === []) {
            return [];
        }

        // Resolve against the full set so public variables may reference the site's own keys.
        $resolved = $this->references->resolveForSite($site->id, $variables)->variables;

        return array_intersect_key($resolved, $public);
    }

    public function cacheKey(SiteData $site, string $mode, ?string $commit): string
    {
        $env = $this->environment($site);
        ksort($env);

        return hash('sha256', (string) json_encode([
            'v' => 1,
            'site' => $site->id,
            'mode' => $mode,
            'commit' => $commit !== null ? strtolower($commit) : null,
            'repository' => $site->repository,
            'runtime' => $site->runtime->value,
            'php' => $site->phpVersion,
            'node' => $site->nodeVersion,
            'dockerfile' => $site->dockerfile,
            'env' => hash('sha256', (string) json_encode($env)),
        ]));
    }

    /** Runtime hint for kiln-builder (php|node|bun|deno|static). */
    public static function runtimeHint(SiteData $site): ?string
    {
        return match (true) {
            $site->runtime->isPhp() => 'php',
            $site->runtime->value === 'node' => 'node',
            $site->runtime->value === 'bun' => 'bun',
            $site->runtime->value === 'deno' => 'deno',
            $site->runtime->value === 'static' => 'static',
            default => null,
        };
    }
}
