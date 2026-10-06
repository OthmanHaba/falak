<?php

namespace Falak\Sites\Infrastructure;

use Falak\Sites\Application\Actions\SaveEnvironment;
use Falak\Sites\Contracts\Exceptions\EnvironmentChanged;
use Falak\Sites\Contracts\SiteEnvironments;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Support\Facades\DB;

final class EloquentSiteEnvironments implements SiteEnvironments
{
    public function __construct(private readonly SaveEnvironment $save) {}

    public function set(string $siteId, array $values, ?string $userId, string $auditAction, ?int $baseVersion = null): ?int
    {
        $site = Site::query()->findOrFail(strtolower($siteId));

        return DB::transaction(function () use ($site, $values, $userId, $auditAction, $baseVersion) {
            $current = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->lockForUpdate()->first();

            if ($baseVersion !== null && $current !== null && $current->version !== $baseVersion) {
                throw new EnvironmentChanged("Someone saved version {$current->version} of the variables in the meantime.");
            }

            $variables = [...array_map('strval', $current->variables ?? []), ...array_map('strval', $values)];

            return ($this->save)($site, $variables, $current->exposed ?? [], $userId, $auditAction)?->version;
        });
    }
}
