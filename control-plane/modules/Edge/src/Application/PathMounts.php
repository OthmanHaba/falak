<?php

namespace Falak\Edge\Application;

use Illuminate\Validation\ValidationException;
use Falak\Edge\Domain\Models\Mount;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * Functions served on a path of another site (`app.example.com/api/*`). The host site's Caddy routes the path: to the
 * function's gateway when the function runs on the same server, else over HTTPS to the function's own domain.
 */
final class PathMounts
{
    public const MAX_PER_SITE = 20;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    /**
     * @param  ?string  $service  a public service of a compose host site: the path is served on that service's
     *                            domains only (null: on every route of the site)
     */
    public function create(SiteData $function, string $hostSiteId, string $path, bool $strip, ?string $service = null): Mount
    {
        $host = $this->sites->find(strtolower($hostSiteId));
        $path = '/'.trim(trim($path), '/');

        if ($host === null || $host->organizationId !== $function->organizationId) {
            throw ValidationException::withMessages(['site_id' => 'Pick a service of this organization.']);
        }

        // The primary service by name is its own route too (rules name it; domains use null).
        $service = $service === ComposeServiceDomains::primaryService($host) ? $service : ComposeServiceDomains::normalize($host, $service);

        if ($host->runtime->isFunction() || $host->id === $function->id) {
            throw ValidationException::withMessages(['site_id' => 'Mount the function on a site, not on a function.']);
        }

        if ($path === '/' || preg_match(Mount::PATH_PATTERN, $path) !== 1) {
            throw ValidationException::withMessages(['path_prefix' => 'Use a path like /api (letters, digits, - _ . ~ and /; not the root).']);
        }

        if (Mount::query()->where('site_id', $host->id)->where('compose_service', $service)->where('path_prefix', $path)->exists()) {
            throw ValidationException::withMessages(['path_prefix' => "{$path} of {$host->name} already serves a function."]);
        }

        if (Mount::query()->where('site_id', $host->id)->count() >= self::MAX_PER_SITE) {
            throw ValidationException::withMessages(['path_prefix' => 'A site can have at most '.self::MAX_PER_SITE.' function paths.']);
        }

        $mount = Mount::query()->create([
            'organization_id' => $function->organizationId,
            'site_id' => $host->id,
            'function_site_id' => $function->id,
            'compose_service' => $service,
            'path_prefix' => $path,
            'strip_prefix' => $strip,
        ]);
        $this->audit->record('edge.mount.created', 'site', $host->id, array_filter(['path' => $path, 'function' => $function->slug, 'service' => $service]), $function->organizationId);
        $this->changes->siteChanged($host->id);

        return $mount;
    }

    public function delete(Mount $mount): void
    {
        $mount->delete();
        $this->audit->record('edge.mount.deleted', 'site', $mount->site_id, ['path' => $mount->path_prefix], $mount->organization_id);
        $this->changes->siteChanged($mount->site_id);
    }

    /** A function's servers or domains changed: its host sites route to it differently. */
    public function functionChanged(string $functionSiteId): void
    {
        foreach (Mount::query()->where('function_site_id', $functionSiteId)->pluck('site_id')->unique() as $hostId) {
            $this->changes->siteChanged($hostId);
        }
    }

    /** A site was deleted: drop the mounts it hosted or served. */
    public function siteDeleted(string $siteId): void
    {
        $hosts = Mount::query()->where('function_site_id', $siteId)->pluck('site_id')->unique()->all();
        Mount::query()->where('site_id', $siteId)->orWhere('function_site_id', $siteId)->delete();

        foreach ($hosts as $hostId) {
            $this->changes->siteChanged($hostId);
        }
    }
}
