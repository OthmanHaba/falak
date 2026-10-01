<?php

namespace Kiln\Deployments\Application\Orchestration;

use Kiln\Builds\Contracts\BuildService;
use Kiln\Deployments\Contracts\FunctionSources;
use Kiln\Deployments\Domain\Enums\StepKind;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Projects\Contracts\VariableReferences;
use Kiln\Sites\Contracts\ComposeSites;
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
        private readonly VariableReferences $references,
        private readonly ComposeSites $compose,
        private readonly FunctionSources $functions,
        private readonly AgentDirectory $agents,
    ) {}

    // ---- Docker Compose (docs/COMPOSE_TEMPLATES.md §1.4) -------------------------------------------------------

    /**
     * @return ?array<string, mixed>
     */
    private function compose(DeploymentStep $step, Deployment $deployment, SiteData $site, string $serverId): ?array
    {
        return match ($step->kind) {
            StepKind::Fetch => $this->composeFiles($site, $this->composeRelease($deployment, $site), (string) $deployment->release_id, $serverId, $deployment),
            StepKind::Hook => $this->composeLeader($site, $this->composeRelease($deployment, $site), (string) $deployment->release_id),
            StepKind::Activate => $this->composeUp($site, $this->composeRelease($deployment, $site), (string) $deployment->release_id, $serverId, $deployment),
            StepKind::Switch => $this->composeUp($site, $this->storedRelease((string) $deployment->target_release_id), (string) $deployment->target_release_id, $serverId, $deployment),
            StepKind::Revert => $this->composeUp($site, $this->storedRelease((string) ($step->meta['release_id'] ?? '')), (string) ($step->meta['release_id'] ?? ''), $serverId, $deployment),
            default => throw new RuntimeException("{$step->kind->value} is not a compose step."),
        };
    }

    /**
     * The rendered compose release, rendered once (at the first step that needs it) and stored on the release.
     *
     * @return array{yaml: string, env: array<string, string>, leader: array<string, list<string>>, source: string, version?: ?int, registry?: bool}
     *
     * @throws RuntimeException
     */
    public function composeRelease(Deployment $deployment, SiteData $site): array
    {
        $release = Release::query()->find($deployment->release_id) ?? throw new RuntimeException('The release no longer exists.');

        if (is_array($release->compose)) {
            return $release->compose;
        }

        $images = [];
        $version = null;
        $registry = false;
        $assets = [];
        $repoFiles = null;

        if ($deployment->build_id !== null) {
            $build = $this->builds->composeFor($deployment->build_id) ?? throw new RuntimeException('The build produced no compose file.');
            [$yaml, $images, $registry, $assets, $repoFiles] = [$build->content, $build->images, $build->registryAuth !== null, $build->assets ?? [], $build->repoFiles()];
        } else {
            $inline = $this->compose->content($site->id) ?? throw new RuntimeException('The site has no compose file; add one in Settings → Compose.');
            [$yaml, $version] = [$inline->content, $inline->version];
        }

        $rendered = $this->compose->render($site->id, $yaml, $images, (string) $deployment->release_id, $repoFiles);

        $data = [
            'yaml' => $rendered->yaml,
            'env' => [...$this->releaseVariables($site), ...array_filter([
                'KILN_SITE_ID' => self::upper($site->id),
                'KILN_DEPLOYMENT_ID' => self::upper($deployment->id),
                'KILN_RELEASE_ID' => self::upper($deployment->release_id),
            ])],
            'leader' => $rendered->leaderCommands,
            'source' => $site->compose?->source->value ?? 'repo',
            'version' => $version,
            'registry' => $registry,
            // Repository files the project mounts (written under repo/; kept with the release for rollbacks).
            'assets' => $assets,
        ];

        $release->forceFill(['compose' => $data])->save();

        return $data;
    }

    /**
     * @return array{yaml: string, env: array<string, string>, leader: array<string, list<string>>, source: string}
     */
    private function storedRelease(string $releaseId): array
    {
        $compose = $releaseId !== '' ? Release::query()->find(strtolower($releaseId))?->compose : null;

        if (! is_array($compose)) {
            throw new RuntimeException('The release has no compose files to return to.');
        }

        return $compose;
    }

    private function composeDirectory(SiteData $site, string $releaseId): string
    {
        return $site->rootPath.'/releases/'.self::upper($releaseId);
    }

    /**
     * docker.compose.pull (FETCH): the release's compose.yaml + .env, images pulled.
     *
     * @param  array{yaml: string, env: array<string, string>}  $release
     * @return array<string, mixed>
     */
    private function composeFiles(SiteData $site, array $release, string $releaseId, string $serverId, Deployment $deployment): array
    {
        $env = $this->composeEnv($release, $serverId);
        $assets = array_values((array) ($release['assets'] ?? []));

        // Repository files under repo/ need an agent that writes them (feature compose.v2).
        if ($assets !== [] && ! ($this->agents->forServers([$serverId])[$serverId] ?? null)?->supports('compose.v2')) {
            throw new RuntimeException('The Kiln agent on this server is too old for compose projects that mount repository files; update it first.');
        }

        return array_filter([
            'project' => $site->slug,
            'directory' => $this->composeDirectory($site, $releaseId),
            'files' => [
                ['name' => 'compose.yaml', 'content' => $release['yaml']],
                ['name' => '.env', 'content' => self::composeDotenv($env)],
            ],
            'assets' => $assets === [] ? null : $assets,
            'env' => (object) $env,
            'project_env_file' => '.env',
            'registry_auth' => $this->composeRegistryAuth($release),
        ], fn ($v) => $v !== null);
    }

    /**
     * docker.compose.up --wait (ACTIVATE, manual rollback, failure rollback).
     *
     * @param  array{yaml: string, env: array<string, string>}  $release
     * @return array<string, mixed>
     */
    private function composeUp(SiteData $site, array $release, string $releaseId, string $serverId, Deployment $deployment): array
    {
        return [
            ...$this->composeFiles($site, $release, $releaseId, $serverId, $deployment),
            'pull' => 'missing',
            'remove_orphans' => true,
            'wait' => true,
            'wait_timeout_s' => max(1, min(3600, (int) config('deployments.compose.wait_timeout', 300))),
        ];
    }

    /**
     * system.exec on the leader: `docker compose run --rm <service> <argv>` for every kiln.deploy.leader_command.
     * Arguments are shell-escaped one by one (never re-parsed by a shell).
     *
     * @param  array{leader: array<string, list<string>>}  $release
     * @return ?array<string, mixed>
     */
    private function composeLeader(SiteData $site, array $release, string $releaseId): ?array
    {
        $leader = (array) ($release['leader'] ?? []);

        if ($leader === []) {
            return null;
        }

        $lines = ['set -e'];

        foreach ($leader as $service => $argv) {
            $command = implode(' ', array_map('escapeshellarg', array_map('strval', (array) $argv)));
            $lines[] = 'echo '.escapeshellarg("==> {$service}: ".implode(' ', (array) $argv));
            $lines[] = 'docker compose -p '.escapeshellarg($site->slug).' --env-file .env -f compose.yaml run --rm -T --no-deps '.escapeshellarg((string) $service).($command !== '' ? ' '.$command : '');
        }

        return [
            'script' => implode("\n", $lines)."\n",
            'cwd' => $this->composeDirectory($site, $releaseId),
        ];
    }

    /**
     * @param  array{env: array<string, string>}  $release
     * @return array<string, string>
     */
    private function composeEnv(array $release, string $serverId): array
    {
        $env = [...array_map('strval', (array) $release['env']), 'KILN_SERVER_ID' => (string) self::upper($serverId)];

        return array_filter($env, fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array{yaml: string, registry?: bool}  $release
     * @return ?array<string, string>
     */
    private function composeRegistryAuth(array $release): ?array
    {
        $registry = rtrim((string) preg_replace('#^https?://#i', '', (string) config('builds.registry.url')), '/');

        return ($release['registry'] ?? false) || ($registry !== '' && str_contains($release['yaml'], $registry.'/'))
            ? $this->registryAuthFor($registry.'/')
            : null;
    }

    /**
     * Project .env for `--env-file`: single-quoted (literal, no interpolation) where possible. The same values
     * are passed as the compose process environment, which wins over the file for interpolation.
     *
     * @param  array<string, string>  $env
     */
    public static function composeDotenv(array $env): string
    {
        $lines = [];

        foreach ($env as $key => $value) {
            $value = (string) $value;
            $lines[] = $key.'='.match (true) {
                preg_match('/^[A-Za-z0-9_.,:\/@+=\-]*$/', $value) === 1 => $value,
                ! str_contains($value, "'") && ! str_contains($value, "\n") => "'{$value}'",
                default => '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"',
            };
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    public static function upper(?string $ulid): ?string
    {
        return $ulid !== null ? strtoupper($ulid) : null;
    }

    /**
     * @return ?array<string, mixed> null when the step has nothing to run (compose leader step without leader commands)
     *
     * @throws RuntimeException when the payload cannot be built (missing artifact, image, port…)
     */
    public function for(DeploymentStep $step, Deployment $deployment, SiteData $site): ?array
    {
        $serverId = (string) $step->server_id;

        if ($site->runtime === SiteRuntime::Compose) {
            return $this->compose($step, $deployment, $site, $serverId);
        }

        if ($site->runtime === SiteRuntime::Function) {
            return $this->functionRelease($step, $deployment, $site, $serverId);
        }

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

    public function timeout(StepKind $kind, ?string $commandType = null): int
    {
        if ($commandType === 'fn.release.apply') {
            return max(60, min(3600, (int) config('deployments.timeouts.function', 600)));
        }

        if ($commandType === 'docker.compose.up') {
            return max(60, min(3600, (int) config('deployments.timeouts.compose_up', 600) + (int) config('deployments.compose.wait_timeout', 300)));
        }

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
        $env = $this->releaseVariables($site);
        $this->rememberEnvironment($deployment, $env);

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
     * Keep the variables the release's `.env` is written with (first prepare wins; all servers get the same file),
     * so Processes can put the same environment into the release's program env — and a rollback restores it.
     *
     * @param  array<string, string>  $env
     */
    private function rememberEnvironment(Deployment $deployment, array $env): void
    {
        $release = $deployment->release_id !== null ? Release::query()->find($deployment->release_id) : null;

        if ($release !== null && $release->environment === null) {
            $release->forceFill(['environment' => $env])->save();
        }
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

        $variables = $this->resolved($site, $this->sites->deployVariables($site->id, $serverId, $context));
        $variables['KILN_SITE_ID'] = (string) self::upper($site->id);
        $variables['KILN_SERVER_ID'] = (string) self::upper($serverId);

        return array_filter($variables, fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The site's latest environment with `${{ service.KEY }}` references resolved (Projects).
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when a reference cannot be resolved (fails the deployment with the reason)
     */
    public function releaseVariables(SiteData $site): array
    {
        return $this->resolved($site, $this->sites->environment($site->id)?->variables ?? []);
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     *
     * @throws RuntimeException
     */
    private function resolved(SiteData $site, array $variables): array
    {
        $result = $this->references->resolveForSite($site->id, $variables);

        if (! $result->ok()) {
            throw new RuntimeException($result->errorSummary());
        }

        return $result->variables;
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

        $env = $this->releaseVariables($site);
        // The app listens on its container port; Kiln publishes it on the site's loopback host ports (blue/green).
        $listen = $site->listenPort();
        $env = array_filter([...$env, 'PORT' => (string) $listen, ...$this->injected($deployment, $site, $serverId)], fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);
        $health = (array) $deployment->setting('health', []);

        return array_filter([
            'site' => $site->slug,
            'image' => $ref,
            'pull' => 'missing',
            'registry_auth' => $auth,
            'container_port' => $listen,
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

    // ---- Functions -----------------------------------------------------------------------------------------------

    /**
     * fn.release.apply for the release the step puts live: the deployment's own (its commit is the version hash), the
     * one a rollback targets, or the previous one when a failure is rolled back.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function functionRelease(DeploymentStep $step, Deployment $deployment, SiteData $site, string $serverId): array
    {
        $releaseId = (string) match ($step->kind) {
            StepKind::Activate => $deployment->release_id,
            StepKind::Switch => $deployment->target_release_id,
            StepKind::Revert => $step->meta['release_id'] ?? '',
            default => throw new RuntimeException("{$step->kind->value} is not a function step."),
        };
        $hash = $step->kind === StepKind::Activate ? $deployment->commit : Release::query()->whereKey($releaseId)->value('commit');
        $source = $releaseId !== '' && is_string($hash) ? $this->functions->find($site->id, $hash) : null;

        if ($source === null) {
            throw new RuntimeException('The function version of this release no longer exists.');
        }

        // The gateway sets PORT (the runtime listens on a fixed port inside the container).
        $env = array_diff_key([
            ...$this->releaseVariables($site),
            ...$this->injected($deployment, $site, $serverId),
            'KILN_RELEASE_ID' => self::upper($releaseId),
        ], ['PORT' => true]);
        $env = array_filter($env, fn ($v, $k) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH);

        $files = [];

        foreach ($source->files as $path => $content) {
            $files[] = ['path' => (string) $path, 'content' => (string) $content];
        }

        return array_filter([
            'site' => $site->slug,
            'release' => strtolower($releaseId),
            'image' => $source->image,
            'pull' => 'missing',
            'registry_auth' => $this->registryAuthFor($source->image),
            'entrypoint' => $source->entrypoint,
            'files' => $files,
            'env' => (object) array_map('strval', $env),
            'scaling' => $source->scaling,
            'limits' => $source->limits,
            // The function's current access rules, also for rollbacks (a revoked key never comes back with an old
            // release). Only when restricted: agents before fn.v2 do not know the field (they refuse the release
            // rather than serve it unprotected).
            'access' => $source->access !== [] ? $source->access : null,
            'labels' => (object) array_filter([
                'kiln.site.id' => self::upper($site->id),
                'kiln.deployment.id' => self::upper($deployment->id),
                'kiln.release.id' => self::upper($releaseId),
            ]),
        ], fn ($v) => $v !== null);
    }
}
