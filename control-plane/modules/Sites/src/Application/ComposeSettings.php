<?php

namespace Kiln\Sites\Application;

use Illuminate\Validation\ValidationException;
use Kiln\Sites\Contracts\ComposeSource;
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

    public function __construct(
        private readonly YamlComposeInspector $inspector,
        private readonly SiteRules $rules,
        private readonly SiteDomains $domains,
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
     * @return list<array{service: string, port: int, domain: ?string, host_port: int}>
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

            $out[] = ['service' => $service, 'port' => $port, 'domain' => $domain, 'host_port' => $hostPort];
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

    public static function source(mixed $value, bool $hasContent): ComposeSource
    {
        return ComposeSource::tryFrom((string) $value) ?? ($hasContent ? ComposeSource::Inline : ComposeSource::Repo);
    }
}
