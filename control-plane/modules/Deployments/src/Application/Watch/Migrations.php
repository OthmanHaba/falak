<?php

namespace Falak\Deployments\Application\Watch;

use Falak\Deployments\Domain\Enums\ReleaseStatus;
use Falak\Deployments\Domain\Models\Release;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteRuntime;

/**
 * Whether a site's deployments run database migrations, which a rollback doesn't reverse: a deploy script line that
 * migrates (`artisan migrate`, `doctrine:migrations:migrate`, `rails db:migrate`, `prisma migrate deploy`,
 * `manage.py migrate`, `alembic upgrade`, …) or, for compose sites, a `falak.deploy.leader_command` in the release.
 */
final class Migrations
{
    private const SCRIPT_PATTERN = '/^(?!\s*#).*(\bmigrate\b|\bmigrations?:(migrate|run|up)\b|\balembic\s+upgrade\b|\bflyway\b.*\bmigrate\b)/im';

    public static function inScript(?string $script): bool
    {
        return $script !== null && preg_match(self::SCRIPT_PATTERN, $script) === 1;
    }

    /**
     * $release: the compose release that was (or is about to be) deployed; the active one when null.
     */
    public static function forSite(SiteData $site, ?Release $release = null): bool
    {
        if ($site->runtime === SiteRuntime::Compose) {
            $release ??= Release::query()->where('site_id', $site->id)->where('status', ReleaseStatus::Active)->latest('activated_at')->first();

            return (array) (($release?->compose ?? [])['leader'] ?? []) !== [];
        }

        // Container images and functions have no deploy script.
        return ! $site->runtime->usesDocker() && self::inScript($site->deployScript);
    }
}
