<?php

namespace Falak\Sites\Application\Actions;

use Illuminate\Validation\ValidationException;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Application\OctanePorts;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteUpdated;

/**
 * Laravel toggles. Scheduler / Horizon / Octane are converged by Processes (listening to SiteUpdated);
 * maintenance mode runs `artisan down` / `artisan up` on every server right away.
 *
 * Octane: the server defaults to FrankenPHP on FrankenPHP sites and Swoole otherwise; the port is allocated by
 * {@see OctanePorts} (never taken from the request) and kept when Octane is switched off, so switching it back
 * on reuses it when still free.
 */
final class UpdateLaravelSettings
{
    public function __construct(
        private readonly RunSiteCommand $run,
        private readonly AuditLog $audit,
        private readonly OctanePorts $octanePorts,
    ) {}

    public function __invoke(Site $site, LaravelSettings $settings, ?string $userId): void
    {
        if (! $site->framework->isLaravel()) {
            throw ValidationException::withMessages(['laravel' => 'Only Laravel sites have these settings.']);
        }

        if ($settings->octane && ! $site->runtime->isPhp()) {
            throw ValidationException::withMessages(['octane' => 'Octane needs a PHP runtime.']);
        }

        $before = $site->laravel;
        $site->loadMissing('targets');

        $settings = $this->octanePorts->resolve($site, $settings->with(
            octaneServer: $settings->octaneServer ?? $before->octaneServer,
            octanePort: $before->octanePort,
        ));

        if ($before == $settings) {
            return;
        }

        if ($before->maintenance !== $settings->maintenance) {
            $artisan = $settings->maintenance ? 'artisan down --retry=60' : 'artisan up';

            foreach ($site->targets as $target) {
                /** @var SiteTarget $target */
                if ($target->status === TargetStatus::Ready) {
                    ($this->run)($site, $target->server_id, "{$site->phpBinary()} {$artisan}", $userId, $settings->maintenance ? 'site.maintenance_enabled' : 'site.maintenance_disabled');
                }
            }
        }

        $site->forceFill(['laravel' => $settings])->save();

        $changed = array_keys(array_diff_assoc($settings->toArray(), $before->toArray()));

        $this->audit->record('site.laravel_updated', 'site', $site->id, $settings->toArray(), $site->organization_id);
        SiteUpdated::dispatch($site->id, $site->organization_id, ['laravel', ...array_map(fn ($key) => "laravel.{$key}", $changed)], $site->serverIds());
    }
}
