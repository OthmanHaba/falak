<?php

namespace Falak\Deployments\Application\Actions;

use Falak\Deployments\Domain\Models\ReleaseWatch;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Validation\Rule;

/**
 * The watch after a release goes live (Settings → Deploy, PUT /api/v1/sites/{site}/release-watch). Changes apply to
 * the next deployment's window; an open window keeps the settings it started with.
 */
final class UpdateWatchSettings
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * Validation rules (every field optional: a partial update keeps the rest).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'health' => ['sometimes', 'boolean'],
            'health_failures' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'crashes' => ['sometimes', 'boolean'],
            'errors' => ['sometimes', 'boolean'],
            'issues' => ['sometimes', 'boolean'],
            'on_trigger' => ['sometimes', Rule::in([ReleaseWatch::ROLLBACK, ReleaseWatch::ALERT_ONLY])],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated against {@see self::rules()}
     */
    public function __invoke(SiteData $site, array $data, ?string $actorId = null): SiteSettings
    {
        $settings = SiteSettings::for($site);
        $columns = [
            'enabled' => 'watch_enabled', 'minutes' => 'watch_minutes', 'health' => 'watch_health', 'health_failures' => 'watch_health_failures',
            'crashes' => 'watch_crashes', 'errors' => 'watch_errors', 'issues' => 'watch_issues', 'on_trigger' => 'watch_on_trigger',
        ];

        foreach ($columns as $field => $column) {
            if (array_key_exists($field, $data)) {
                $settings->{$column} = $data[$field];
            }
        }

        $changed = array_keys($settings->getDirty());
        $settings->save();

        if ($changed !== []) {
            $this->audit->record('deployments.watch_updated', 'site', $site->id, ['changed' => $changed, 'enabled' => $settings->watch_enabled, 'on_trigger' => $settings->watch_on_trigger], $site->organizationId, $actorId);
        }

        return $settings;
    }
}
