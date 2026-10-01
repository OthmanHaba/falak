<?php

namespace Kiln\Edge\Application;

use Illuminate\Validation\ValidationException;
use Kiln\Edge\Domain\Models\Mount;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;

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
    public function create(SiteData $function, string $hostSiteId, string $path, bool $strip): Mount
    {
        $host = $this->sites->find(strtolower($hostSiteId));
        $path = '/'.trim(trim($path), '/');

        if ($host === null || $host->organizationId !== $function->organizationId) {
            throw ValidationException::withMessages(['site_id' => 'Pick a service of this organization.']);
        }

        if ($host->runtime->isFunction() || $host->id === $function->id) {
            throw ValidationException::withMessages(['site_id' => 'Mount the function on a site, not on a function.']);
        }

        if ($path === '/' || preg_match(Mount::PATH_PATTERN, $path) !== 1) {
            throw ValidationException::withMessages(['path_prefix' => 'Use a path like /api (letters, digits, - _ . ~ and /; not the root).']);
        }

        if (Mount::query()->where('site_id', $host->id)->where('path_prefix', $path)->exists()) {
            throw ValidationException::withMessages(['path_prefix' => "{$path} of {$host->name} already serves a function."]);
        }

        if (Mount::query()->where('site_id', $host->id)->count() >= self::MAX_PER_SITE) {
            throw ValidationException::withMessages(['path_prefix' => 'A site can have at most '.self::MAX_PER_SITE.' function paths.']);
        }

        $mount = Mount::query()->create([
            'organization_id' => $function->organizationId,
            'site_id' => $host->id,
            'function_site_id' => $function->id,
            'path_prefix' => $path,
            'strip_prefix' => $strip,
        ]);
        $this->audit->record('edge.mount.created', 'site', $host->id, ['path' => $path, 'function' => $function->slug], $function->organizationId);
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
