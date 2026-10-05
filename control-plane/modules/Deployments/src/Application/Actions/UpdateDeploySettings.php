<?php

namespace Falak\Deployments\Application\Actions;

use Illuminate\Validation\ValidationException;
use Falak\Deployments\Domain\Enums\Strategy;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;

final class UpdateDeploySettings
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function __invoke(SiteData $site, array $data, ?string $actorId = null): SiteSettings
    {
        $settings = SiteSettings::for($site);
        $strategy = Strategy::from((string) $data['strategy']);

        if (! in_array($strategy, Strategy::for($site->runtime), true)) {
            throw ValidationException::withMessages(['strategy' => "{$strategy->label()} is not available for {$site->runtime->label()} sites."]);
        }

        $settings->fill([
            'strategy' => $strategy,
            'batch_size' => (int) $data['batch_size'],
            'keep_releases' => (int) $data['keep_releases'],
            'health_enabled' => (bool) $data['health_enabled'],
            'health_path' => ($data['health_path'] ?? null) ?: null,
            'health_status' => (int) $data['health_status'],
            'health_timeout_s' => (int) $data['health_timeout_s'],
            'health_retries' => (int) $data['health_retries'],
            'health_retry_delay_s' => (int) $data['health_retry_delay_s'],
        ]);

        $changed = array_keys($settings->getDirty());
        $settings->save();

        if ($changed !== []) {
            $this->audit->record('deployments.settings_updated', 'site', $site->id, ['changed' => $changed], $site->organizationId, $actorId);
        }

        return $settings;
    }
}
