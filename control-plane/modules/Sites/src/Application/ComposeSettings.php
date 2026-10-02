<?php

namespace Kiln\Sites\Application;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Sites\Application\Compose\RepoComposeInspection;
use Kiln\Sites\Contracts\ComposeServiceExtraction;
use Kiln\Sites\Contracts\ComposeSource;
use Kiln\Sites\Contracts\Data\ComposeConfig;
use Kiln\Sites\Contracts\Data\ComposeSummary;
use Kiln\Sites\Contracts\Data\DomainChoice;
use Kiln\Sites\Contracts\SiteDomains;
use Kiln\Sites\Domain\Models\ComposeVersion;
use Kiln\Sites\Domain\Models\OrganizationSettings;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Infrastructure\Compose\YamlComposeInspector;

/**
 * Validation and persistence of a compose site's source, inline versions and public services
 * (shared by site creation and Settings → Compose).
 */
final class ComposeSettings
{
    public const DOMAIN_PATTERN = '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/';

    /** Permission to replace a service with a Kiln database (Databases' manage permission). */
    public const DATABASE_PERMISSION = 'databases.manage';

    /** Permission to run a service as its own Kiln site. */
    public const SITE_PERMISSION = 'sites.create';

    public function __construct(
        private readonly YamlComposeInspector $inspector,
        private readonly SiteRules $rules,
        private readonly SiteDomains $domains,
        private readonly RepoComposeInspection $inspection,
    ) {}

    /**
     * Explicit domain choices ({type: generated|test|custom, name?}) of public services → host names (null: the test
     * domain). Generated names are `<service>-<slug>.<ip-with-dashes>.<suffix>` for the leader server. Plain strings
     * (custom domains) and nulls are left for {@see publicServices()}.
     *
     * @param  list<array<string, mixed>>  $services
     * @param  list<string>  $serverIds  leader first
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function resolveDomainChoices(string $organizationId, array $services, string $slug, array $serverIds, ?string $siteId = null): array
    {
        foreach ($services as $i => $public) {
            if (! is_array($public) || ! is_array($public['domain'] ?? null)) {
                continue;
            }

            $field = "public_services.{$i}.domain";
            $label = trim((string) ($public['service'] ?? ''), '-_.') ?: 'app';
            $services[$i]['domain'] = $this->domains->resolveChoice($organizationId, DomainChoice::fromInput($public['domain'], $field), "{$label}-{$slug}", $serverIds, $field, $siteId);
        }

        return array_values($services);
    }

    /**
     * Inline compose content must parse, may not build and must pass the policy (unless the organization
     * allows privileged compose).
     *
     * @throws ValidationException
     */
    public function validateInline(string $organizationId, string $content, string $field = 'compose_content'): ComposeSummary
    {
        if (trim($content) === '') {
            throw ValidationException::withMessages([$field => 'Paste a compose file.']);
        }

        $summary = $this->inspector->parse($content);

        if (! $summary->valid()) {
            throw ValidationException::withMessages([$field => $summary->errors]);
        }

        if (($builds = $summary->buildServices()) !== []) {
            throw ValidationException::withMessages([$field => 'Inline compose files cannot use `build:` ('.implode(', ', $builds).'); push the image to a registry and use `image:`, or deploy from a repository.']);
        }

        if ($summary->violations !== [] && ! OrganizationSettings::for($organizationId)->allow_privileged_compose) {
            throw ValidationException::withMessages([$field => $summary->violations]);
        }

        return $summary;
    }

    /**
     * Normalise public services and allocate loopback host ports (kept for services that already had one).
     *
     * @param  list<array<string, mixed>>  $services
     * @param  list<string>  $serverIds
     * @param  ?ComposeSummary  $summary  when known (inline sources), services must exist in it
     * @return list<array{service: string, port: int, domain: ?string, host_port: int, health_check_path?: string}>
     *
     * @throws ValidationException
     */
    public function publicServices(array $services, array $serverIds, ?ComposeSummary $summary, ?Site $site = null): array
    {
        $existing = [];

        foreach ((array) $site?->public_services as $public) {
            if (is_array($public) && isset($public['service'], $public['host_port'])) {
                $existing[(string) $public['service']] = (int) $public['host_port'];
            }
        }

        $taken = $this->rules->portsInUse($serverIds, $site?->id);
        $out = [];
        $seen = [];
        $domains = [];

        foreach (array_values($services) as $i => $public) {
            $service = trim((string) ($public['service'] ?? ''));
            $port = (int) ($public['port'] ?? 0);
            $domain = isset($public['domain']) && trim((string) $public['domain']) !== '' ? strtolower(trim((string) $public['domain'], " .\t")) : null;

            if ($service === '' || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $service) !== 1) {
                throw ValidationException::withMessages(["public_services.{$i}.service" => 'Pick a service.']);
            }

            if (isset($seen[$service])) {
                throw ValidationException::withMessages(["public_services.{$i}.service" => "{$service} is already public."]);
            }

            if ($port < 1 || $port > 65535) {
                throw ValidationException::withMessages(["public_services.{$i}.port" => 'Enter the container port (1–65535).']);
            }

            if ($domain !== null && (preg_match(self::DOMAIN_PATTERN, $domain) !== 1 || isset($domains[$domain]))) {
                throw ValidationException::withMessages(["public_services.{$i}.domain" => 'Enter a valid domain (each service needs its own).']);
            }

            if ($summary !== null) {
                $found = $summary->service($service);

                if ($found === null) {
                    throw ValidationException::withMessages(["public_services.{$i}.service" => "The compose file has no service {$service}."]);
                }
            }

            $hostPort = $existing[$service] ?? null;

            if ($hostPort === null || in_array($hostPort, $taken, true)) {
                $hostPort = $this->rules->freePort($serverIds, $site?->id, [...$taken, ...array_column($out, 'host_port')]);
            }

            $taken[] = $hostPort;
            $seen[$service] = true;

            if ($domain !== null) {
                $domains[$domain] = true;
            }

            $health = isset($public['health_check_path']) && trim((string) $public['health_check_path']) !== '' ? trim((string) $public['health_check_path']) : null;

            if ($health !== null && preg_match('#^/\S{0,254}$#', $health) !== 1) {
                throw ValidationException::withMessages(["public_services.{$i}.health_check_path" => 'Start the path with / (e.g. /health).']);
            }

            $out[] = ['service' => $service, 'port' => $port, 'domain' => $domain, 'host_port' => $hostPort] + ($health !== null ? ['health_check_path' => $health] : []);
        }

        return $out;
    }

    /**
     * Store a new inline version when the content changed. Returns the (new or current) version number.
     */
    public function saveVersion(Site $site, string $content, ?string $userId): int
    {
        $content = rtrim(str_replace("\r\n", "\n", $content))."\n";
        $latest = ComposeVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();

        if ($latest !== null && $latest->content === $content) {
            return $latest->version;
        }

        $version = ($latest->version ?? 0) + 1;

        ComposeVersion::query()->create([
            'site_id' => $site->id,
            'version' => $version,
            'content' => $content,
            'created_by' => $userId,
            'created_at' => now(),
        ]);

        $keep = max(1, (int) config('sites.compose.keep_versions', 50));
        ComposeVersion::query()->where('site_id', $site->id)->where('version', '<=', $version - $keep)->delete();

        return $version;
    }

    /**
     * Repository project settings (docs/plans/COMPOSE_APPS.md): compose files, profiles, kept decisions and the
     * services to extract. Database/site decisions are not stored here: {@see ComposeServiceExtraction} records them
     * once the database or site exists.
     *
     * @param  array<string, mixed>  $data
     * @return array{files: list<string>, profiles: list<string>, services: array<string, array<string, mixed>>, adjustments: array<string, mixed>, extract: list<array{service: string, mode: string, engine: ?string, database_id: ?string, site: array<string, mixed>}>}
     *
     * @throws ValidationException
     */
    public function project(array $data, ?Site $site = null): array
    {
        $files = array_key_exists('compose_files', $data) && is_array($data['compose_files'])
            ? array_values(array_map('strval', $data['compose_files']))
            : (isset($data['compose_file']) && $data['compose_file'] !== '' ? [(string) $data['compose_file']] : ($site?->composeFiles() ?? []));
        $profiles = array_key_exists('compose_profiles', $data) ? array_values(array_map('strval', (array) $data['compose_profiles'])) : array_values(array_map('strval', (array) $site?->compose_profiles));
        $services = array_filter((array) $site?->compose_services, fn ($d) => is_array($d) && in_array($d['mode'] ?? null, [ComposeConfig::MODE_DATABASE, ComposeConfig::MODE_SITE], true));
        $extract = [];

        foreach ((array) ($data['compose_services'] ?? []) as $service => $decision) {
            $service = (string) $service;

            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $service) !== 1) {
                throw ValidationException::withMessages(['compose_services' => "“{$service}” is not a compose service name."]);
            }

            $mode = (string) ($decision['mode'] ?? ComposeConfig::MODE_KEEP);

            if ($mode === ComposeConfig::MODE_KEEP) {
                // Back in the stack; an already created database or site is left as it is.
                unset($services[$service]);

                continue;
            }

            if (($services[$service]['mode'] ?? null) === $mode) {
                continue; // already extracted
            }

            if ($mode === ComposeConfig::MODE_DATABASE && empty($decision['engine']) && empty($decision['database_id'])) {
                throw ValidationException::withMessages(["compose_services.{$service}.engine" => 'Pick the database engine.']);
            }

            $extract[] = [
                'service' => $service,
                'mode' => $mode,
                'engine' => isset($decision['engine']) ? (string) $decision['engine'] : null,
                'database_id' => isset($decision['database_id']) ? strtolower((string) $decision['database_id']) : null,
                'site' => is_array($decision['site'] ?? null) ? $decision['site'] : [],
            ];
        }

        $adjustments = array_key_exists('compose_adjustments', $data)
            ? ['keep_binds' => array_values(array_map('strval', (array) ($data['compose_adjustments']['keep_binds'] ?? [])))]
            : (is_array($site?->compose_adjustments) ? $site->compose_adjustments : []);

        return ['files' => $files, 'profiles' => $profiles, 'services' => $services, 'adjustments' => array_filter($adjustments), 'extract' => $extract];
    }

    /**
     * Check a repository project before saving it: it loads, its public services exist and every required variable
     * has a value. Repositories Kiln can't read (plain git, API errors) are checked at the first deploy instead.
     *
     * @param  list<string>  $files
     * @param  list<string>  $profiles
     * @param  list<array<string, mixed>>  $publicServices
     * @param  array<string, mixed>  $variables
     *
     * Returns the merged project (YAML), or null when Kiln can't read the repository.
     *
     * @throws ValidationException
     */
    public function verifyRepository(string $connectionId, string $repository, string $branch, array $files, array $profiles, array $publicServices, array $variables): ?string
    {
        $result = $this->inspection->inspect($connectionId, $repository, $branch, $files, $profiles);

        if (($result['no_api'] ?? false) === true) {
            return null;
        }

        if (($result['errors'] ?? []) !== []) {
            throw ValidationException::withMessages(['compose_files' => $result['errors']]);
        }

        $names = array_column((array) $result['services'], 'name');

        foreach (array_values($publicServices) as $i => $public) {
            $service = (string) ($public['service'] ?? '');

            if ($service !== '' && ! in_array($service, $names, true)) {
                throw ValidationException::withMessages(["public_services.{$i}.service" => "The compose project has no service {$service}."]);
            }
        }

        $missing = array_values(array_map(
            fn (array $v) => $v['name'],
            array_filter((array) $result['variables'], fn (array $v) => $v['required'] && $v['default'] === null && trim((string) ($variables[$v['name']] ?? '')) === ''),
        ));

        if ($missing !== []) {
            throw ValidationException::withMessages(['variables' => 'The compose project needs '.implode(', ', $missing).'.']);
        }

        return isset($result['original']) ? (string) $result['original'] : null;
    }

    /**
     * Replace services with Kiln databases / own sites. Failures leave the service in the stack and come back as
     * warnings (the site itself already exists).
     *
     * @param  list<array{service: string, mode: string, engine: ?string, database_id: ?string, site: array<string, mixed>}>  $extract
     * @return list<string> warnings
     */
    public function extract(Site $site, array $extract, ?string $compose = null): array
    {
        $extraction = app(ComposeServiceExtraction::class);
        $access = app(OrganizationAccess::class);
        $actor = Auth::user();
        $warnings = [];

        foreach ($extract as $item) {
            // A Kiln database or site is created on the actor's behalf: they need that permission too (system actors —
            // no user — run on behalf of someone already checked).
            $permission = $item['mode'] === ComposeConfig::MODE_DATABASE ? self::DATABASE_PERMISSION : self::SITE_PERMISSION;

            if ($actor !== null && ! $access->can($actor, $site->organization_id, $permission)) {
                $warnings[] = "{$item['service']} stays in the stack: you don't have permission to create ".($item['mode'] === ComposeConfig::MODE_DATABASE ? 'databases.' : 'sites.');

                continue;
            }

            try {
                if ($item['mode'] === ComposeConfig::MODE_DATABASE) {
                    $extraction->toDatabase($site->id, $item['service'], $item['database_id'], (string) $item['engine'], $compose);
                } else {
                    $extraction->toSite($site->id, $item['service'], $item['site'], $compose);
                }
            } catch (ValidationException $e) {
                $warnings[] = "{$item['service']} stays in the stack: ".collect($e->errors())->flatten()->first();
            }
        }

        return $warnings;
    }

    public static function source(mixed $value, bool $hasContent): ComposeSource
    {
        return ComposeSource::tryFrom((string) $value) ?? ($hasContent ? ComposeSource::Inline : ComposeSource::Repo);
    }
}
