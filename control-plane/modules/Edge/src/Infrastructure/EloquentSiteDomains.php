<?php

namespace Falak\Edge\Infrastructure;

use Illuminate\Validation\ValidationException;
use Falak\Edge\Application\Actions\AddDomain;
use Falak\Edge\Application\ComposeServiceDomains;
use Falak\Edge\Application\DnsTargets;
use Falak\Edge\Application\GeneratedDomains;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Domain;
use Falak\Sites\Contracts\Data\DomainChoice;
use Falak\Sites\Contracts\DomainType;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteDomains;

final class EloquentSiteDomains implements SiteDomains
{
    public function __construct(
        private readonly GeneratedDomains $generated,
        private readonly DnsTargets $targets,
    ) {}

    public function primaryDomains(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        // The site's own route: a compose site's other public services have primary domains of their own.
        $primary = Domain::query()
            ->whereIn('site_id', $siteIds)
            ->where('is_primary', true)
            ->get(['site_id', 'compose_service', 'name', 'www_redirect'])
            ->groupBy('site_id');
        $out = [];

        foreach ($primary as $siteId => $domains) {
            $domain = $domains->firstWhere('compose_service', null);

            if ($domain === null && ($site = app(SiteDirectory::class)->find((string) $siteId)) !== null) {
                $domain = $domains->firstWhere('compose_service', ComposeServiceDomains::primaryService($site));
            }

            if ($domain !== null) {
                $out[(string) $siteId] = $domain->servedHost();
            }
        }

        return $out;
    }

    public function resolveChoice(string $organizationId, ?DomainChoice $choice, string $label, array $serverIds, string $field, ?string $siteId = null): ?string
    {
        $type = $choice?->type ?? $this->defaultType($organizationId);

        return match ($type) {
            DomainType::Test => self::testDomainBase() !== null
                ? null
                : throw ValidationException::withMessages([$field => 'No test domain is configured (FALAK_TEST_DOMAIN). Generate a domain or enter your own.']),
            DomainType::Generated => $this->generate($organizationId, $label, $serverIds, $field, $siteId),
            DomainType::Custom => $this->custom((string) $choice?->name, $field),
        };
    }

    public function attach(string $siteId, string $name): void
    {
        $site = app(SiteDirectory::class)->find($siteId) ?? throw ValidationException::withMessages(['domain' => 'Unknown site.']);

        app(AddDomain::class)($site, $name);
    }

    /**
     * Test domain when the operator runs one, else a generated name, else the user must bring a domain.
     */
    public function defaultType(string $organizationId): DomainType
    {
        return match (true) {
            self::testDomainBase() !== null => DomainType::Test,
            $this->generated->suffix($organizationId) !== null => DomainType::Generated,
            default => DomainType::Custom,
        };
    }

    public static function testDomainBase(): ?string
    {
        $base = config('sites.test_domain');

        return is_string($base) && trim($base, '. ') !== '' ? strtolower(trim($base, '. ')) : null;
    }

    /**
     * @param  list<string>  $serverIds
     */
    private function generate(string $organizationId, string $label, array $serverIds, string $field, ?string $siteId): string
    {
        $suffix = $this->generated->suffix($organizationId)
            ?? throw ValidationException::withMessages([$field => 'Generated domains are turned off for this organization (Settings → Domains). Enter your own domain.']);
        $leader = $this->targets->for($organizationId, array_slice($serverIds, 0, 1), $siteId)[0]
            ?? throw ValidationException::withMessages([$field => 'Pick a server first: the generated domain points at its IP address.']);

        if (($zone = $this->generated->zone($organizationId)) !== null) {
            if ($leader->ipv4 === null && $leader->ipv6 === null) {
                throw ValidationException::withMessages([$field => "{$leader->name} has no public IP address yet, so its DNS record cannot be created. Enter your own domain."]);
            }

            // The first free name: shop.example.com, else shop-2.example.com, …
            $base = GeneratedDomains::label($label);
            for ($n = 1; $n <= 20; $n++) {
                $name = ($n === 1 ? $base : substr($base, 0, 60)."-{$n}").'.'.$zone->name;
                if (! Domain::query()->where('name', $name)->exists()) {
                    return $name;
                }
            }

            throw ValidationException::withMessages([$field => "No free name for {$base} under {$zone->name}; enter a domain."]);
        }

        if ($leader->ipv4 === null) {
            throw ValidationException::withMessages([$field => "{$leader->name} has no public IPv4 address yet, so no domain can be generated for it. Enter your own domain."]);
        }

        $name = GeneratedDomains::name($label, $leader->ipv4, $suffix);
        $this->ensureAvailable($name, $field);

        return $name;
    }

    private function custom(string $name, string $field): string
    {
        $name = DomainChoice::normalize($name);

        if (preg_match(Domain::HOSTNAME, $name) !== 1 || str_starts_with($name, '*.') || strlen($name) > 253) {
            throw ValidationException::withMessages([$field => 'Enter a domain name like app.example.com.']);
        }

        if (($base = self::testDomainBase()) !== null && str_ends_with($name, ".{$base}")) {
            throw ValidationException::withMessages([$field => "Names under {$base} are test domains; pick “Test domain” instead."]);
        }

        $this->ensureAvailable($name, $field);

        return $name;
    }

    private function ensureAvailable(string $name, string $field): void
    {
        $apex = (string) preg_replace('/^www\./', '', $name);
        $taken = Domain::query()
            ->where(fn ($q) => $q->where('name', $name)->orWhere(fn ($q) => $q->where('name', $apex)->where('www_redirect', '!=', WwwRedirect::None->value)))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$field => "{$name} is already used by another site."]);
        }
    }
}
