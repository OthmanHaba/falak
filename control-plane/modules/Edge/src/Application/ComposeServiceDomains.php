<?php

namespace Falak\Edge\Application;

use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\DnsRecord;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\SiteSetting;
use Falak\Edge\Events\DomainAdded;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\Data\PublicService;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Domains of a compose site's public services (docs/plans/COMPOSE_APPS.md, phase 2). Every public service's domains
 * are edge_domains rows: `compose_service` null belongs to the primary (first) public service — the site's own route,
 * as for any site — and a service name to that service. A row naming the current primary (after the public services
 * were reordered) still belongs to it.
 *
 * Sites keeps a domain per public service in `public_services` (the creation-time choice, and the read model):
 * {@see import()} turns those into rows, {@see mirror()} writes each service's first row back.
 */
final class ComposeServiceDomains
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ComposeSites $compose,
    ) {}

    /** Public services of a compose site (none for other runtimes). */
    public static function publicServices(SiteData $site): array
    {
        return $site->runtime === SiteRuntime::Compose && $site->compose !== null ? $site->compose->publicServices : [];
    }

    /**
     * The service picker of the Networking settings: every public service of a compose site, primary first (empty for
     * other sites).
     *
     * @return list<array{service: string, primary: bool, port: int, test_domain: ?string, health_check_path: ?string}>
     */
    public static function options(SiteData $site): array
    {
        return array_map(fn (PublicService $public, int $i) => [
            'service' => $public->service,
            'primary' => $i === 0,
            'port' => $public->port,
            'test_domain' => $public->testDomain,
            'health_check_path' => $public->healthCheckPath,
        ], self::publicServices($site), array_keys(self::publicServices($site)));
    }

    public static function primaryService(SiteData $site): ?string
    {
        return self::publicServices($site)[0]->service ?? null;
    }

    /**
     * The `compose_service` value for a service of the site: null for the site itself / the primary service.
     *
     * @throws ValidationException keyed $field when the service is not public
     */
    public static function normalize(SiteData $site, ?string $service, string $field = 'service'): ?string
    {
        $service = $service !== null ? trim($service) : null;

        if ($service === null || $service === '' || $service === self::primaryService($site)) {
            return null;
        }

        foreach (self::publicServices($site) as $public) {
            if ($public->service === $service) {
                return $service;
            }
        }

        throw ValidationException::withMessages([$field => $site->runtime === SiteRuntime::Compose
            ? "{$service} is not a public service of this compose site."
            : 'Only compose sites have services.']);
    }

    /**
     * Limits a query on a table with `compose_service` to the rows of one route: null / the primary service → the
     * site's own rows (and rows naming the primary); another service → its rows.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, SiteData $site, ?string $service): Builder
    {
        $primary = self::primaryService($site);

        if ($service === null || $service === $primary) {
            return $query->where(fn (Builder $q) => $primary === null
                ? $q->whereNull('compose_service')
                : $q->whereNull('compose_service')->orWhere('compose_service', $primary));
        }

        return $query->where('compose_service', $service);
    }

    /**
     * Rules (redirects, basic auth, headers, mounts) of one route: rows for the whole site (null) and rows for that
     * service.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function ruleScope(Builder $query, ?string $service): Builder
    {
        return $service === null
            ? $query->whereNull('compose_service')
            : $query->where(fn (Builder $q) => $q->whereNull('compose_service')->orWhere('compose_service', $service));
    }

    /**
     * Every compose site's public service domains as rows (the migration that introduced per-service domains).
     *
     * @param  iterable<string>  $siteIds
     */
    public function importAll(iterable $siteIds): void
    {
        foreach ($siteIds as $siteId) {
            $this->import((string) $siteId);
        }
    }

    /**
     * Rows for the domains Sites holds per public service (new site, or a domain chosen in Settings → Compose), then
     * the first row of each service written back. A name another site already routes is skipped (resolveChoice
     * refuses those when the domain is chosen). Returns whether rows were added or moved.
     */
    public function import(string $siteId): bool
    {
        $site = $this->sites->find($siteId);
        $publics = $site !== null ? self::publicServices($site) : [];

        if ($site === null || $publics === []) {
            return false;
        }

        $changed = $this->retarget($site);

        foreach ($publics as $i => $public) {
            if ($public->domain === null) {
                continue;
            }

            $service = $i === 0 ? null : $public->service;
            $existing = Domain::query()->where('name', $public->domain)->first();

            if ($existing !== null) {
                // Chosen for another service of the same site in Settings → Compose: it moves there.
                if ($existing->site_id === $site->id && $existing->compose_service !== $service && ($i > 0 || $existing->compose_service !== $public->service)) {
                    DB::transaction(function () use ($existing, $site, $service) {
                        $primary = ! self::scope(Domain::query()->where('site_id', $site->id), $site, $service)->lockForUpdate()->exists();
                        $existing->forceFill(['compose_service' => $service, 'is_primary' => $primary])->save();
                    });
                    $changed = true;
                }

                continue;
            }

            $domain = DB::transaction(function () use ($site, $public, $service) {
                $primary = ! self::scope(Domain::query()->where('site_id', $site->id), $site, $service)->lockForUpdate()->exists();

                return Domain::query()->create([
                    'organization_id' => $site->organizationId,
                    'site_id' => $site->id,
                    'compose_service' => $service,
                    'name' => $public->domain,
                    'is_primary' => $primary,
                    'www_redirect' => WwwRedirect::None,
                    'tls_mode' => TlsMode::Auto,
                ]);
            });

            // Records Falak created for the name while it belonged to the site (tag falak:site:<id>) now belong to the
            // row: the next sync updates them in place (comment included) instead of re-creating them.
            DnsRecord::query()->where('site_id', $site->id)->whereNull('domain_id')->whereIn('name', $domain->hosts())
                ->update(['domain_id' => $domain->id, 'site_id' => null, 'status' => DnsRecord::PENDING]);

            DomainAdded::dispatch($domain->id, $site->id, $site->organizationId, $domain->name, $domain->is_primary);
            $changed = true;
        }

        $this->mirror($site);

        return $changed;
    }

    /**
     * The first public service changed (reordered, or the previous one split out or no longer public): the site's own
     * domain rows (compose_service null) belonged to the previous one and are handed to it by name, the new first
     * service's rows become the site's own. Edge remembers the first service per site (edge_site_settings
     * compose_primary); a site seen for the first time just records it. Returns whether rows moved.
     */
    public function retarget(SiteData $site): bool
    {
        $current = self::primaryService($site);

        return DB::transaction(function () use ($site, $current) {
            $settings = SiteSetting::query()->whereKey($site->id)->lockForUpdate()->first() ?? new SiteSetting(['site_id' => $site->id]);
            $previous = $settings->compose_primary;
            $moved = false;

            if ($previous !== null && $current !== null && $previous !== $current) {
                $own = Domain::query()->where('site_id', $site->id)->whereNull('compose_service')->get();
                $theirs = Domain::query()->where('site_id', $site->id)->where('compose_service', $current)->get();
                $own->each(fn (Domain $d) => $d->forceFill(['compose_service' => $previous])->save());
                $theirs->each(fn (Domain $d) => $d->forceFill(['compose_service' => null])->save());
                $moved = $own->isNotEmpty() || $theirs->isNotEmpty();
            }

            if ($previous !== $current) {
                $settings->forceFill(['compose_primary' => $current])->save();
            }

            return $moved;
        });
    }

    /** The service whose domains are the site's own rows as Edge last saw it (null: not recorded yet). */
    public static function recordedPrimary(string $siteId): ?string
    {
        return SiteSetting::query()->whereKey($siteId)->value('compose_primary');
    }

    /** Each public service's first domain (primary row first) written back to Sites. */
    public function mirror(SiteData|string $site): void
    {
        $site = is_string($site) ? $this->sites->find($site) : $site;

        if ($site === null || ($publics = self::publicServices($site)) === []) {
            return;
        }

        $this->compose->setPublicDomains($site->id, collect($publics)->mapWithKeys(fn (PublicService $public) => [
            $public->service => self::scope(Domain::query()->where('site_id', $site->id), $site, $public->service)
                ->orderByDesc('is_primary')->orderBy('name')->value('name'),
        ])->all());
    }
}
