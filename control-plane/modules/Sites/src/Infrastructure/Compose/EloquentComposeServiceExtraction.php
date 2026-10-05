<?php

namespace Falak\Sites\Infrastructure\Compose;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Application\Actions\SaveEnvironment;
use Falak\Sites\Application\Compose\ComposeInterpolation;
use Falak\Sites\Application\Compose\ComposeNetworks;
use Falak\Sites\Application\Compose\ComposeProject;
use Falak\Sites\Application\Compose\ComposeProjectException;
use Falak\Sites\Application\Compose\RedisCommand;
use Falak\Sites\Application\Compose\RepoComposeInspection;
use Falak\Sites\Application\Compose\ServiceReferences;
use Falak\Sites\Contracts\ComposeServiceExtraction;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\ComposeRewrites;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Presets\Preset;
use Falak\Sites\Events\ComposeServiceExtracted;
use Falak\Sites\Events\SiteUpdated;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class EloquentComposeServiceExtraction implements ComposeServiceExtraction
{
    /**
     * Images Falak can replace with a managed database, by engine (SQL: the image name; Redis / Valkey: the official
     * repositories only — redis-stack, bitnami/redis etc. stay containers).
     */
    private const ENGINE_IMAGES = [
        'postgresql' => ['postgres', 'postgis', 'pgvector', 'timescaledb'],
        'mysql' => ['mysql', 'percona'],
        'mariadb' => ['mariadb'],
        'redis' => ['redis'],
        'valkey' => ['valkey/valkey'],
    ];

    /** Key-value engines: an instance per service, named <stack>-<service>. */
    private const KEY_VALUE = ['redis', 'valkey'];

    /** The service's database name, by engine (official image conventions). */
    private const NAME_KEYS = [
        'postgresql' => ['POSTGRES_DB'],
        'mysql' => ['MYSQL_DATABASE'],
        'mariadb' => ['MARIADB_DATABASE', 'MYSQL_DATABASE'],
    ];

    public function __construct(
        private readonly DatabaseProvisioner $databases,
        private readonly DatabaseDirectory $databaseDirectory,
        private readonly SiteFactory $sites,
        private readonly ProjectDirectory $projects,
        private readonly SiteDomains $domains,
        private readonly ComposeSites $composeSites,
        private readonly SourceControlGateway $sourceControl,
        private readonly ServerDirectory $servers,
    ) {}

    public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine, ?string $compose = null): DatabaseData
    {
        $stack = $this->stack($siteId);
        [$document, $definition] = $this->service($stack, $service, $compose);
        $engine = strtolower($engine);

        if (! array_key_exists($engine, self::ENGINE_IMAGES)) {
            throw ValidationException::withMessages(['engine' => 'Falak manages PostgreSQL, MySQL, MariaDB, Redis and Valkey databases.']);
        }

        $keyValue = in_array($engine, self::KEY_VALUE, true);
        $image = strtolower((string) ($definition['image'] ?? ''));
        $repository = $keyValue ? self::repository($image) : basename(self::repository($image));

        if (($image !== '' || $keyValue) && ! in_array($repository, self::ENGINE_IMAGES[$engine], true)) {
            throw ValidationException::withMessages(['engine' => $image === ''
                ? "Service {$service} has no image: only services running the official ".self::label($engine).' image can become a Falak '.self::label($engine).'.'
                : "Service {$service} runs {$image}, not ".($keyValue ? 'the official '.self::label($engine).' image' : 'a '.self::label($engine).' image').'.']);
        }

        $existing = null;

        if ($databaseId !== null) {
            $existing = $this->databaseDirectory->find(strtolower($databaseId));

            if ($existing === null || $existing->organizationId !== $stack->organization_id) {
                throw ValidationException::withMessages(['database_id' => 'Database not found.']);
            }

            if ($existing->engine !== $engine) {
                throw ValidationException::withMessages(['database_id' => "{$existing->name} is a ".self::label($existing->engine).' database, not '.self::label($engine).'.']);
            }

            // References resolve within one environment: the database must be (or become) a service next to the stack.
            $placed = $this->projects->projectOf('database', $existing->id);
            $here = $this->projects->projectOf('site', $stack->id);

            if ($placed !== null && $here !== null && $placed->environmentId !== $here->environmentId) {
                throw ValidationException::withMessages(['database_id' => "{$existing->name} belongs to another environment; pick a database of this environment or create one."]);
            }
        }

        $leader = $stack->toData()->leader()?->serverId;

        if ($existing === null && $leader === null) {
            throw ValidationException::withMessages(['service' => 'The stack has no server to create the database on.']);
        }

        if ($existing === null && $keyValue) {
            $this->assertRunsCache((string) $leader, $engine, $service);
        }

        $this->claim($stack, $service);

        try {
            $database = $existing ?? ($keyValue
                ? $this->databases->create($stack->organization_id, (string) $leader, $engine, $this->instanceName($stack, $service, (string) $leader), Auth::id(), RedisCommand::settings($definition['command'] ?? null))
                : $this->databases->create($stack->organization_id, (string) $leader, $engine, $this->databaseName($stack, $service, $engine, $definition, (string) $leader), Auth::id()));
        } catch (Throwable $e) {
            $this->release($stack, $service);

            throw $e;
        }

        $decision = [
            'mode' => 'database',
            'database_id' => $database->id,
            'rewrites' => ServiceReferences::find($document, $service, $keyValue ? 'cache' : 'database', $this->stackVariables($stack)),
        ];

        // rediss:// / valkeys:// values keep pointing at the service: a Falak instance has no TLS (ComposeSettings warns).
        if ($keyValue && ($tls = ServiceReferences::tlsReferences($document, $service, $this->stackVariables($stack))) !== []) {
            $decision['tls_references'] = $tls;
        }

        // REDIS_PORT / REDIS_PASSWORD next to a host of another prefix, in a group that also points at another service:
        // left as they are (ComposeSettings warns).
        if ($keyValue && ($unclear = ServiceReferences::unclearCompanions($document, $service, $this->stackVariables($stack))) !== []) {
            $decision['unclear_companions'] = $unclear;
        }

        // Healthchecks of the remaining services that name the service (`redis-cli -h cache ping`, `pg_isready -h db`): a
        // command isn't rewritten (its host, port and password flags differ per tool), so ComposeSettings warns.
        if (($healthchecks = ServiceReferences::healthchecksNaming($document, $service)) !== []) {
            $decision['healthchecks'] = $healthchecks;
        }

        $this->record($stack, $service, $decision);

        // On the canvas "<stack> <service>" (handle e.g. shop-db): the stack's own name is usually the database's too.
        ComposeServiceExtracted::dispatch($stack->id, $stack->organization_id, $service, 'database', $database->id, "{$stack->name} {$service}");
        $this->syncSplitSites($stack);

        return $database;
    }

    public function toSite(string $siteId, string $service, array $site, ?string $compose = null): SiteData
    {
        $stack = $this->stack($siteId);
        [$document, $definition] = $this->service($stack, $service, $compose);
        $data = $stack->toData();
        $stackVariables = $this->stackVariables($stack);
        // Read before record() drops it from public_services: Edge moves the stack's own domains along.
        $wasPrimary = (array_values(array_filter((array) $stack->public_services, 'is_array'))[0]['service'] ?? null) === $service;

        $build = $definition['build'] ?? null;
        $context = is_array($build) ? ($build['context'] ?? '.') : (is_string($build) ? $build : null);
        // Docker when picked (or no framework given); otherwise the framework's own runtime (Laravel, Node.js on the
        // host), which can't join the stack's network.
        $framework = (string) ($site['framework'] ?? 'docker');
        $runtime = (string) ($site['runtime'] ?? ($framework === 'docker' ? 'docker' : Preset::for(Framework::from($framework))->defaultRuntime()->value));
        $php = SiteRuntime::tryFrom($runtime)?->isPhp() === true;

        $derived = array_filter([
            'name' => $service,
            'runtime' => $runtime,
            'source_connection_id' => $data->sourceConnectionId,
            'repository' => $data->repository,
            'branch' => $data->branch,
            'push_to_deploy' => $stack->push_to_deploy,
            'root_directory' => $context !== null ? $this->repositoryPath($stack, (string) $context) : null,
            'dockerfile' => $runtime === 'docker' && is_array($build) && isset($build['dockerfile']) ? (string) $build['dockerfile'] : null,
            'docker_image' => $runtime === 'docker' && $context === null && isset($definition['image']) ? (string) $definition['image'] : null,
            'container_port' => $runtime === 'docker' ? $this->firstPort($definition) : null,
            'php_version' => $php ? (string) config('sites.default_php', '8.4') : null,
            'server_ids' => $data->serverIds(),
            'leader_server_id' => $data->leader()?->serverId,
            // Its env files' keys, then its `environment:` (which wins), as the stack gave them to the service.
            'variables' => $this->interpolate([...$this->envFileVariables($stack, $definition), ...ServiceReferences::environment($definition['environment'] ?? [])], $stackVariables) ?: null,
        ], fn ($value) => $value !== null);

        $placement = $this->projects->projectOf('site', $stack->id);
        $this->claim($stack, $service);

        try {
            $created = $this->sites->create($stack->organization_id, Auth::id(), [...$derived, ...$site], $placement !== null
            ? new SitePlacement($placement->projectId, $placement->environmentId, $placement->x + 360, $placement->y, (string) ($site['name'] ?? $service))
            : null);
        } catch (Throwable $e) {
            $this->release($stack, $service);

            throw $e;
        }

        // Network names as the stack's Compose resolves them (`name: ${NETWORK}` reads the stack's variables).
        $networks = ComposeNetworks::check(ComposeNetworks::of($document, $stack->slug, $service, $stackVariables));
        $aliases = array_intersect_key(ComposeNetworks::aliases($document, $stack->slug, $service, $stackVariables), array_flip($networks['networks']));
        $this->record($stack, $service, [
            'mode' => 'site',
            'site_id' => $created->site->id,
            'rewrites' => ServiceReferences::find($document, $service, 'site', $stackVariables),
            // A Docker site joins these (under the service's name, plus the aliases it declares per network) on the
            // stack's servers: see ComposeSites::stackNetworks(). [] (network_mode) joins none. Ones the agent can't join
            // (names, more than it takes) are left out here and reported once.
            'networks' => $networks['networks'],
            ...($aliases !== [] ? ['network_aliases' => $aliases] : []),
            // Plain networks of the stack's compose project (real name => key): the agent creates a missing one with
            // Compose's labels, so the site can deploy before the stack's first `up`. Configured ones (driver, ipam,
            // internal, …) only Compose creates: the site waits for the stack's first deploy (`waited_networks`).
            'compose_networks' => array_intersect_key(ComposeNetworks::owned($document, $stack->slug, $service, $stackVariables), array_flip($networks['networks'])),
            ...(($waited = array_values(array_intersect(ComposeNetworks::waited($document, $stack->slug, $service, $stackVariables), $networks['networks']))) !== [] ? ['waited_networks' => $waited] : []),
            ...($networks['skipped'] !== [] ? ['skipped_networks' => $networks['skipped']] : []),
            // Hosts in its environment as the site gets it: after the stack's variables are filled in.
            'uses' => ServiceReferences::uses($document, $service, $stackVariables),
        ]);

        ComposeServiceExtracted::dispatch($stack->id, $stack->organization_id, $service, 'site', $created->site->id, $created->site->name, $wasPrimary);
        $this->syncSplitSites($stack);

        return $created->site;
    }

    public function rewrites(string $siteId): ComposeRewrites
    {
        $stack = Site::query()->find(strtolower($siteId));
        $groups = [];

        foreach ((array) ($stack?->compose_services ?? []) as $decision) {
            if (! is_array($decision)) {
                continue;
            }

            $fill = match ($decision['mode'] ?? 'keep') {
                'database' => $this->databaseFill((string) ($decision['database_id'] ?? '')),
                'site' => $this->siteFill((string) ($decision['site_id'] ?? '')),
                default => null,
            };

            if ($fill === null) {
                continue;
            }

            foreach ((array) ($decision['rewrites'] ?? []) as $group => $templates) {
                foreach ((array) $templates as $variable => $template) {
                    $groups[(string) $group][(string) $variable] ??= $fill((string) $template);
                }
            }
        }

        foreach ($groups as &$variables) {
            ksort($variables);
        }

        return new ComposeRewrites($groups);
    }

    /**
     * @return ?callable(string): string `{ref:KEY}` → `${{ <database service>.KEY }}`
     */
    private function databaseFill(string $databaseId): ?callable
    {
        $database = $databaseId !== '' ? $this->databaseDirectory->find($databaseId) : null;

        if ($database === null) {
            return null;
        }

        $name = $this->projects->projectOf('database', $database->id)?->name ?? $database->name;

        return fn (string $template) => (string) preg_replace_callback('/\{ref:([A-Z_]+)\}/', fn (array $m) => '${{ '.$name.'.'.$m[1].' }}', $template);
    }

    /**
     * @return ?callable(string): string `{url}` / `{host}` → the split-out site's primary domain (https)
     */
    private function siteFill(string $siteId): ?callable
    {
        $site = $siteId !== '' ? Site::query()->find($siteId) : null;
        $host = $site !== null ? ($this->domains->primaryDomains([$site->id])[$site->id] ?? $site->testDomain()) : null;

        if ($host === null) {
            return null;
        }

        return fn (string $template) => str_replace(['{url}', '{host}'], ["https://{$host}", $host], $template);
    }

    private function stack(string $siteId): Site
    {
        $stack = Site::query()->with('targets')->find(strtolower($siteId));

        if ($stack === null || $stack->runtime !== SiteRuntime::Compose) {
            throw ValidationException::withMessages(['service' => 'Only services of a Docker Compose stack can run as Falak services.']);
        }

        return $stack;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the document and the service's definition
     */
    private function service(Site $stack, string $service, ?string $compose): array
    {
        $yaml = $compose ?? $this->composeFile($stack);

        try {
            $document = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw ValidationException::withMessages(['compose' => 'The compose file is not valid YAML: '.$e->getMessage()]);
        }

        $definition = is_array($document) ? ($document['services'][$service] ?? null) : null;

        if (! is_array($definition)) {
            throw ValidationException::withMessages(['service' => "The compose file has no service {$service}."]);
        }

        return [$document, $definition];
    }

    /**
     * The stack's compose project: stored (inline), or the merged project of its files read from the repository on
     * the branch under its root directory — what verifyRepository() returns and the builder deploys, so build
     * contexts are relative to the root directory.
     *
     * @throws ValidationException|SourceControlException
     */
    private function composeFile(Site $stack): string
    {
        if (($stack->compose_source ?? ComposeSource::Repo) === ComposeSource::Inline) {
            return $this->composeSites->content($stack->id)?->content
                ?? throw ValidationException::withMessages(['compose' => 'The stack has no compose file.']);
        }

        if ($stack->source_connection_id === null || $stack->repository === null) {
            throw ValidationException::withMessages(['compose' => 'Could not read the compose project from the repository; pass the compose file.']);
        }

        $root = trim((string) $stack->root_directory, '/');
        $prefix = $root === '' || $root === '.' ? '' : ComposeProject::clean($root).'/';

        try {
            $project = ComposeProject::load(
                fn (string $path) => $this->sourceControl->file((string) $stack->source_connection_id, (string) $stack->repository, (string) $stack->branch, $prefix.$path),
                $stack->composeFiles(),
                array_values(array_map('strval', (array) $stack->compose_profiles)),
            );
        } catch (ComposeProjectException $e) {
            throw ValidationException::withMessages(['compose' => $e->getMessage()]);
        }

        return EloquentComposeSites::dump($project['doc']);
    }

    /**
     * The keys of a service's env files (`env_file:` entries of the merged project, relative to the stack's root
     * directory), read from the repository like the inspection reads them; later files win. Falak's own `.env` (the
     * stack's variables) and files Falak can't read (inline stacks, plain git servers, missing optional files) add
     * nothing.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private function envFileVariables(Site $stack, array $definition): array
    {
        $entries = $definition['env_file'] ?? [];
        $entries = is_array($entries) && array_is_list($entries) ? $entries : [$entries];

        if (($stack->compose_source ?? ComposeSource::Repo) === ComposeSource::Inline || $stack->source_connection_id === null || $stack->repository === null) {
            return [];
        }

        $root = trim((string) $stack->root_directory, '/');
        $variables = [];

        foreach ($entries as $entry) {
            $path = (string) preg_replace('#^(\./)+#', '', (string) (is_array($entry) ? ($entry['path'] ?? '') : $entry));

            // Falak's own .env (the stack's variables), absolute paths and paths leaving the repository add nothing.
            if ($path === '' || $path === '.env' || str_starts_with($path, '/')) {
                continue;
            }

            // Relative to the stack's root directory; `../shared.env` may sit above it, inside the repository.
            $segments = [];
            foreach (explode('/', ($root === '' || $root === '.' ? '' : $root.'/').$path) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    if ($segments === []) {
                        continue 2;
                    }
                    array_pop($segments);

                    continue;
                }
                $segments[] = $segment;
            }

            if ($segments === []) {
                continue;
            }

            try {
                $content = $this->sourceControl->file((string) $stack->source_connection_id, (string) $stack->repository, (string) $stack->branch, implode('/', $segments));
            } catch (SourceControlException) {
                $content = null;
            }

            $variables = [...$variables, ...RepoComposeInspection::envFile((string) $content)];
        }

        return array_filter($variables, fn (string $key) => ! str_starts_with($key, 'FALAK_'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * A build context of the merged project (relative to the stack's root directory, wherever the compose files sit)
     * as a repository path. Null = the repository root.
     */
    private function repositoryPath(Site $stack, string $context): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://|^git@#i', $context) === 1) {
            throw ValidationException::withMessages(['service' => 'The service builds from a remote context; only folders of the repository can become a Falak site.']);
        }

        $parts = [];

        foreach (explode('/', trim(($stack->root_directory ? $stack->root_directory.'/' : '').$context, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts === []) {
                    throw ValidationException::withMessages(['service' => "The build context {$context} is outside the repository."]);
                }
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    /** @param  array<string, mixed>  $definition */
    private function firstPort(array $definition): ?int
    {
        foreach ([...(array) ($definition['ports'] ?? []), ...(array) ($definition['expose'] ?? [])] as $port) {
            $target = is_array($port) ? ($port['target'] ?? null) : last(explode(':', explode('/', (string) $port)[0]));

            if (is_numeric($target) && (int) $target >= 1 && (int) $target <= 65535) {
                return (int) $target;
            }
        }

        return null;
    }

    /**
     * `${VAR}`, `${VAR:-default}` and `$VAR` from the stack's variables (Compose interpolation); unknown ones stay.
     *
     * @param  array<string, string>  $variables
     * @param  array<string, string>  $stack
     * @return array<string, string>
     */
    private function interpolate(array $variables, array $stack): array
    {
        return array_map(fn (string $value) => ComposeInterpolation::apply($value, $stack), $variables);
    }

    /** @return array<string, string> */
    private function stackVariables(Site $stack): array
    {
        return array_map('strval', (array) ($stack->environmentVersions()->orderByDesc('version')->first()?->variables ?? []));
    }

    /** @param  array<string, mixed>  $definition */
    private function databaseName(Site $stack, string $service, string $engine, array $definition, string $serverId): string
    {
        $environment = ServiceReferences::environment($definition['environment'] ?? []);
        $wanted = null;

        foreach (self::NAME_KEYS[$engine] as $key) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $environment[$key] ?? '') === 1) {
                $wanted = strtolower($environment[$key]);
                break;
            }
        }

        $normalize = fn (string $name) => substr(trim((string) preg_replace('/[^a-z0-9_]+/', '_', Str::lower($name)), '_'), 0, 63);
        // The name the stack used, then one prefixed with the stack (another stack's database may hold it: the
        // rewrites point at the new database, so the app reads its name from DATABASE_URL / DB_DATABASE), then _2, _3…
        $base = $normalize($wanted !== null ? "{$stack->slug}_{$wanted}" : "{$stack->slug}_{$service}");
        $taken = array_map(fn (DatabaseData $d) => strtolower($d->name), $this->databaseDirectory->forServer($serverId));

        foreach (array_values(array_unique(array_filter([$wanted, $base]))) as $candidate) {
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        for ($i = 2; ; $i++) {
            $candidate = substr($base, 0, 63 - strlen("_{$i}"))."_{$i}";

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * Takes the service for an extraction under the stack's row lock, before anything is created: of two concurrent
     * requests the second one fails.
     */
    private function claim(Site $stack, string $service): void
    {
        DB::transaction(function () use ($stack, $service) {
            $locked = Site::query()->whereKey($stack->id)->lockForUpdate()->firstOrFail();
            $services = (array) ($locked->compose_services ?? []);

            if (in_array($services[$service]['mode'] ?? 'keep', ['database', 'site', 'pending'], true)) {
                throw ValidationException::withMessages(['service' => "{$service} already runs as a Falak service."]);
            }

            $locked->forceFill(['compose_services' => [...$services, $service => ['mode' => 'pending']]])->save();
            $stack->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Gives the service back to the stack after a failed creation. */
    private function release(Site $stack, string $service): void
    {
        DB::transaction(function () use ($stack, $service) {
            $locked = Site::query()->whereKey($stack->id)->lockForUpdate()->firstOrFail();
            $services = (array) ($locked->compose_services ?? []);
            unset($services[$service]);
            $locked->forceFill(['compose_services' => $services === [] ? null : $services])->save();
            $stack->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** @param  array<string, mixed>  $decision */
    /**
     * Services split out into their own site carry the stack's variables they had: when a service they use moves to a
     * Falak database (or a site), their variables pointing at it get the same rewrite as services still in the stack.
     */
    private function syncSplitSites(Site $stack): void
    {
        $stack->refresh();
        $rewrites = $this->rewrites($stack->id);

        foreach ((array) $stack->compose_services as $service => $decision) {
            if (! is_array($decision) || ($decision['mode'] ?? null) !== 'site' || ! is_string($decision['site_id'] ?? null)) {
                continue;
            }

            $replacements = $rewrites->forService((string) $service);
            $site = Site::query()->find(strtolower($decision['site_id']));
            $current = $site?->environmentVersions()->orderByDesc('version')->first();

            if ($site === null || $replacements === []) {
                continue;
            }

            $variables = array_map('strval', (array) ($current->variables ?? []));
            // A Falak Redis' REDIS_PORT / REDIS_PASSWORD join a REDIS_HOST that had none (as in the stack).
            $next = array_replace($variables, array_intersect_key($replacements, $variables), array_intersect_key($replacements, ['REDIS_PORT' => true, 'REDIS_PASSWORD' => true]));

            if ($next !== $variables) {
                app(SaveEnvironment::class)($site, $next, (array) ($current->exposed ?? []), Auth::id(), 'site.environment_updated');
            }
        }
    }

    private function record(Site $stack, string $service, array $decision): void
    {
        DB::transaction(function () use ($stack, $service, $decision) {
            $locked = Site::query()->whereKey($stack->id)->lockForUpdate()->firstOrFail();
            // The stack no longer serves the service: its public entry goes (Edge moves a split-out service's domains
            // to the new site on ComposeServiceExtracted); the stack's app port follows its primary public service.
            $public = array_values(array_filter((array) ($locked->public_services ?? []), fn ($p) => is_array($p) && ($p['service'] ?? null) !== $service));
            $locked->forceFill([
                'compose_services' => [...(array) ($locked->compose_services ?? []), $service => $decision],
                'public_services' => $public === [] ? null : $public,
                'app_port' => $public[0]['host_port'] ?? null,
            ])->save();
            $stack->setRawAttributes($locked->getAttributes(), true);
        });

        // Routes and the next render follow (Edge recompiles on public_services).
        SiteUpdated::dispatch($stack->id, $stack->organization_id, ['compose_services', 'public_services', 'app_port'], $stack->serverIds());
    }

    private static function label(string $engine): string
    {
        return match ($engine) {
            'postgresql' => 'PostgreSQL',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'redis' => 'Redis',
            'valkey' => 'Valkey',
            default => $engine,
        };
    }

    /** "docker.io/library/redis:7-alpine@sha256:…" → "redis"; "valkey/valkey:8" → "valkey/valkey". */
    private static function repository(string $image): string
    {
        $name = explode('@', $image)[0];
        // A tag follows the last ':' after the last '/' (a registry may carry a port: registry:5000/redis).
        $slash = strrpos($name, '/');
        $colon = strrpos($name, ':');

        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $name = substr($name, 0, $colon);
        }

        return (string) preg_replace('#^(docker\.io/)?(library/)?#', '', $name);
    }

    /**
     * The stack's server must run the engine (one cache engine per server): a clear error when it runs the other one,
     * none (install it first), or can't run Valkey at all (servers.caches_by_os).
     *
     * @throws ValidationException
     */
    private function assertRunsCache(string $serverId, string $engine, string $service): void
    {
        $server = $this->servers->find($serverId);
        $label = self::label($engine);
        $name = $server?->name ?? 'the stack\'s server';
        $running = $server?->cacheEngine;

        if ($running === $engine) {
            return;
        }

        if ($running !== null) {
            throw ValidationException::withMessages(['engine' => "{$service} runs {$label}, but {$name} runs ".self::label($running)." (one cache engine per server). Keep {$service} in the stack, or switch its image to ".($running === 'redis' ? 'redis' : 'valkey/valkey').'.']);
        }

        if (! in_array($engine, $this->servers->installableCaches($serverId), true)) {
            throw ValidationException::withMessages(['engine' => "{$label} isn't available for {$name}'s operating system (Falak offers Valkey on Ubuntu 24.04, 26.04 and Debian 13). Keep {$service} in the stack, or switch its image to redis."]);
        }

        throw ValidationException::withMessages(['engine' => "{$name} doesn't run {$label} yet: install it first (Servers → {$name} → Settings), then pick Falak database for {$service} again."]);
    }

    /**
     * An instance name for the service: <stack>-<service> (Redis / Valkey names: a-z first, then a-z 0-9 _ -, at most
     * 41), then -2, -3… when the server already has it.
     */
    private function instanceName(Site $stack, string $service, string $serverId): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', Str::lower("{$stack->slug}-{$service}")), '-_');
        $base = preg_match('/^[a-z]/', $base) === 1 ? $base : "r-{$base}";
        $base = rtrim(substr($base, 0, 41), '-_');
        $taken = array_map(fn (DatabaseData $d) => strtolower($d->name), $this->databaseDirectory->forServer($serverId));

        if (! in_array($base, [...$taken, 'default', 'falak'], true)) {
            return $base;
        }

        for ($i = 2; ; $i++) {
            $candidate = rtrim(substr($base, 0, 41 - strlen("-{$i}")), '-_')."-{$i}";

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }
}
