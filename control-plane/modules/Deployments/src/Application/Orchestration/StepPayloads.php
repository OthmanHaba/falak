<?php

namespace Kiln\Deployments\Application\Orchestration;

use Kiln\Builds\Contracts\BuildService;
use Kiln\Deployments\Domain\Enums\StepKind;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Sites\Contracts\Data\SharedPath;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;
use RuntimeException;

/**
 * Agent command payloads for deployment steps. ULIDs are uppercased here — the agent boundary —
 * and every deploy.* command carries `context` so lifecycle logs are labelled.
 */
final class StepPayloads
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly BuildService $builds,
        private readonly EdgeRoutes $edge,
    ) {}

    public static function upper(?string $ulid): ?string
    {
        return $ulid !== null ? strtoupper($ulid) : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the payload cannot be built (missing artifact, image, port…)
     */
    public function for(DeploymentStep $step, Deployment $deployment, SiteData $site): array
    {
        $serverId = (string) $step->server_id;

        return match ($step->kind) {
            StepKind::Fetch => $this->fetch($deployment, $site),
            StepKind::Prepare => $this->prepare($deployment, $site, $serverId),
            StepKind::Hook => $this->hook($step, $deployment, $site, $serverId),
            StepKind::Activate => array_filter([
                ...$this->base($deployment, $site),
                'reload' => $this->reload($site),
                'context' => $this->context($deployment, $site),
            ], fn ($v) => $v !== null),
            StepKind::Switch => $this->rollbackTo($deployment, $site, (string) $deployment->target_release_id),
            StepKind::Revert => $this->rollbackTo($deployment, $site, (string) ($step->meta['release_id'] ?? '')),
            StepKind::Swap => $this->swap($deployment, $site, $serverId, $this->imageFor($deployment, $site)),
            StepKind::RevertSwap => $this->swap($deployment, $site, $serverId, [(string) ($step->meta['image'] ?? ''), null]),
            StepKind::Build, StepKind::HealthCheck, StepKind::Restart, StepKind::RevertRestart => throw new RuntimeException("{$step->kind->value} is not an agent command."),
        };
    }

    public function timeout(StepKind $kind): int
    {
        $key = match ($kind) {
            StepKind::Fetch => 'fetch',
            StepKind::Prepare => 'prepare',
            StepKind::Hook => 'hook',
            StepKind::Activate => 'activate',
            StepKind::Switch, StepKind::Revert => 'rollback',
            StepKind::Swap, StepKind::RevertSwap => 'swap',
            default => 'restart',
        };

        return max(1, min(3600, (int) config("deployments.timeouts.{$key}", 600)));
    }

    /**
     * @return array<string, mixed>
     */
    private function base(Deployment $deployment, SiteData $site): array
    {
        return [
            'site' => $site->slug,
            'release_id' => self::upper($deployment->release_id),
            'sites_root' => dirname($site->rootPath),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function context(Deployment $deployment, SiteData $site): array
    {
        return array_filter([
            'site_id' => self::upper($site->id),
            'deployment_id' => self::upper($deployment->id),
            'commit' => $deployment->commit,
            'author' => $deployment->commit_author,
            'branch' => $deployment->branch,
            'trigger' => $deployment->trigger->agentValue(),
            'php_binary' => $site->runtime->isPhp() ? ($site->phpVersion ? "php{$site->phpVersion}" : 'php') : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(Deployment $deployment, SiteData $site): array
    {
        $artifact = $deployment->build_id ? $this->builds->artifactFor($deployment->build_id, (int) config('deployments.artifact_url_ttl', 3600)) : null;

        if ($artifact === null) {
            throw new RuntimeException('The build has no release artifact (was it pruned?).');
        }

        return [
            ...$this->base($deployment, $site),
            'artifact' => $artifact->toPayload(),
            'owner' => ['user' => $site->unixUser],
            'context' => $this->context($deployment, $site),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(Deployment $deployment, SiteData $site, string $serverId): array
    {
        $env = $this->sites->environment($site->id)?->variables ?? [];

        return [
            ...$this->base($deployment, $site),
            'shared_paths' => array_map(fn (SharedPath $path) => ['path' => $path->path, 'type' => $path->type === 'file' ? 'file' : 'dir'], $this->sites->sharedPaths($site->id)),
            'env_file' => ['content' => $this->dotenv([...$env, ...$this->injected($deployment, $site, $serverId)])],
            'owner' => ['user' => $site->unixUser],
            'writable_dirs' => $site->framework->isLaravel() ? ['bootstrap/cache', 'storage'] : [],
            'context' => $this->context($deployment, $site),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hook(DeploymentStep $step, Deployment $deployment, SiteData $site, string $serverId): array
    {
        return [
            ...$this->base($deployment, $site),
            'name' => (string) ($step->meta['name'] ?? 'script'),
            'script' => "set -e\n".(string) ($step->meta['script'] ?? ':'),
            'user' => $site->unixUser,
            'cwd' => (string) ($step->meta['cwd'] ?? 'release'),
            'env' => (object) $this->scriptEnvironment($deployment, $site, $serverId),
            'context' => $this->context($deployment, $site),
        ];
    }

    /**
     * KILN_* variables for deploy script sections (plus exposed site environment and KILN_VAR_*).
     *
     * @return array<string, string>
     */
    public function scriptEnvironment(Deployment $deployment, SiteData $site, string $serverId): array
    {
        $release = self::upper($deployment->release_id);

        $context = array_filter([
            'KILN_COMMIT' => $deployment->commit,
            'KILN_COMMIT_AUTHOR' => $deployment->commit_author,
            'KILN_AUTHOR' => $deployment->commit_author,
            'KILN_COMMIT_MESSAGE' => $deployment->commit_message !== null ? (string) strtok($deployment->commit_message, "\n") : null,
            'KILN_BRANCH' => $deployment->branch,
            'KILN_RELEASE_ID' => $release,
            'KILN_RELEASE_DIR' => $release ? "{$site->rootPath}/releases/{$release}" : null,
            'KILN_DEPLOYMENT_ID' => self::upper($deployment->id),
            'KILN_TRIGGER' => $deployment->trigger->agentValue(),
            'KILN_PHP_BINARY' => $site->runtime->isPhp() ? ($site->phpVersion ? "php{$site->phpVersion}" : 'php') : null,
        ], fn ($v) => $v !== null && $v !== '');

        foreach ($deployment->variables ?? [] as $key => $value) {
            $context[$key] = (string) $value;
        }

        $variables = $this->sites->deployVariables($site->id, $serverId, $context);
        $variables['KILN_SITE_ID'] = (string) self::upper($site->id);
        $variables['KILN_SERVER_ID'] = (string) self::upper($serverId);

        return array_filter($variables, fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Ids injected into the release environment for the APM packages (uppercase ULIDs, like the
     * labels agents attach to telemetry).
     *
     * @return array<string, string>
     */
    public function injected(Deployment $deployment, SiteData $site, string $serverId): array
    {
        return array_filter([
            'KILN_SITE_ID' => self::upper($site->id),
            'KILN_SERVER_ID' => self::upper($serverId),
            'KILN_DEPLOYMENT_ID' => self::upper($deployment->id),
            'KILN_RELEASE_ID' => self::upper($deployment->release_id),
        ]);
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function dotenv(array $variables): string
    {
        $lines = [];

        foreach ($variables as $key => $value) {
            $value = (string) $value;
            $lines[] = $key.'='.($value === '' || preg_match('/^[A-Za-z0-9_.,:\/@+=\-]*$/', $value) === 1
                ? $value
                : '"'.str_replace(['\\', '"', "\n", '$'], ['\\\\', '\\"', '\\n', '\\$'], $value).'"');
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function rollbackTo(Deployment $deployment, SiteData $site, string $releaseId): array
    {
        if ($releaseId === '') {
            throw new RuntimeException('No release to roll back to.');
        }

        return array_filter([
            'site' => $site->slug,
            'sites_root' => dirname($site->rootPath),
            'release_id' => self::upper($releaseId),
            'reload' => $this->reload($site),
            'context' => [...$this->context($deployment, $site), 'trigger' => 'rollback'],
        ], fn ($v) => $v !== null);
    }

    /**
     * @return ?list<array{kind: string, name?: string}>
     */
    private function reload(SiteData $site): ?array
    {
        return match ($site->runtime) {
            SiteRuntime::FrankenPhp => [['kind' => 'frankenphp']],
            SiteRuntime::PhpFpm => $site->phpVersion ? [['kind' => 'php_fpm', 'name' => $site->phpVersion]] : null,
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: ?array<string, string>}
     */
    private function imageFor(Deployment $deployment, SiteData $site): array
    {
        if ($deployment->target_release_id !== null) {
            $image = Release::query()->whereKey($deployment->target_release_id)->value('image');

            return [(string) $image, $this->registryAuthFor((string) $image)];
        }

        if ($deployment->build_id !== null) {
            $image = $this->builds->imageFor($deployment->build_id) ?? throw new RuntimeException('The build produced no image.');

            return [$image->ref, $image->registryAuth];
        }

        return [(string) $site->dockerImage, null];
    }

    /**
     * @return ?array<string, string>
     */
    private function registryAuthFor(string $image): ?array
    {
        $registry = rtrim((string) preg_replace('#^https?://#i', '', (string) config('builds.registry.url')), '/');
        $username = (string) config('builds.registry.username');

        if ($registry === '' || $username === '' || ! str_starts_with($image, $registry.'/')) {
            return null;
        }

        return ['server' => $registry, 'username' => $username, 'password' => (string) config('builds.registry.password')];
    }

    /**
     * @param  array{0: string, 1: ?array<string, string>}  $image
     * @return array<string, mixed>
     */
    private function swap(Deployment $deployment, SiteData $site, string $serverId, array $image): array
    {
        [$ref, $auth] = $image;

        if ($ref === '') {
            throw new RuntimeException('No image to run.');
        }

        if ($site->appPort === null) {
            throw new RuntimeException('The site has no app port for its container.');
        }

        $green = $site->appPort + (int) config('deployments.green_port_offset', 1000);

        if ($green > 65535) {
            throw new RuntimeException('The site app port leaves no room for the green container port.');
        }

        $env = $this->sites->environment($site->id)?->variables ?? [];
        $env = array_filter([...$env, 'PORT' => (string) $site->appPort, ...$this->injected($deployment, $site, $serverId)], fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);
        $health = (array) $deployment->setting('health', []);

        return array_filter([
            'site' => $site->slug,
            'image' => $ref,
            'pull' => 'missing',
            'registry_auth' => $auth,
            'container_port' => $site->appPort,
            'ports' => ['blue' => $site->appPort, 'green' => $green],
            'env' => (object) array_map('strval', $env),
            'health' => [
                'path' => (string) ($health['path'] ?? '/'),
                'expect_status' => (int) ($health['status'] ?? 200),
                'timeout_s' => max(1, (int) ($health['timeout_s'] ?? 10) * max(1, (int) ($health['retries'] ?? 3))),
            ],
            'edge_route_id' => $this->edge->routeId($site->id),
            'labels' => (object) array_filter([
                'kiln.site.id' => self::upper($site->id),
                'kiln.deployment.id' => self::upper($deployment->id),
                'kiln.release.id' => self::upper($deployment->release_id),
            ]),
        ], fn ($v) => $v !== null);
    }
}
