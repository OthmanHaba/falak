<?php

namespace Falak\Sites\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\SiteEnvironmentChanged;
use Illuminate\Support\Facades\DB;

/**
 * Store a new environment version when variables or deploy-script exposure changed.
 */
final class SaveEnvironment
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array<string, string>  $variables
     * @param  list<string>  $exposed
     */
    public function __invoke(Site $site, array $variables, array $exposed, ?string $userId, string $auditAction = 'site.environment_updated'): ?EnvironmentVersion
    {
        $exposed = array_values(array_unique(array_intersect($exposed, array_keys($variables))));
        sort($exposed);

        return DB::transaction(function () use ($site, $variables, $exposed, $userId, $auditAction) {
            /** @var ?EnvironmentVersion $current */
            $current = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->lockForUpdate()->first();
            $before = $current->variables ?? [];
            $changedKeys = $this->changedKeys($before, $variables);
            $exposureChanged = ($current?->exposed ?? []) !== $exposed;

            if ($current && $changedKeys === [] && ! $exposureChanged) {
                return null;
            }

            $version = EnvironmentVersion::query()->create([
                'site_id' => $site->id,
                'version' => ($current->version ?? 0) + 1,
                'variables' => $variables,
                'exposed' => $exposed,
                'changed_keys' => $changedKeys,
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            // Keys only; values never reach the audit log.
            $this->audit->record($auditAction, 'site', $site->id, ['version' => $version->version, 'changed_keys' => $changedKeys], $site->organization_id);

            DB::afterCommit(fn () => SiteEnvironmentChanged::dispatch($site->id, $site->organization_id, $version->version, $changedKeys));

            return $version;
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, string>  $after
     * @return list<string>
     */
    private function changedKeys(array $before, array $after): array
    {
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        $changed = array_values(array_filter($keys, fn ($key) => ! array_key_exists($key, $before) || ! array_key_exists($key, $after) || (string) $before[$key] !== $after[$key]));
        sort($changed);

        return array_map('strval', $changed);
    }
}
