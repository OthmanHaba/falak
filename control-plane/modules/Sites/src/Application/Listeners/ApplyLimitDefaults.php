<?php

namespace Falak\Sites\Application\Listeners;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Limits\Contracts\LimitDefaults;
use Falak\Limits\Contracts\LimitValidator;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Events\ServiceLinked;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteUpdated;
use Falak\Sites\Infrastructure\CommandPayloads;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A new site placed in an environment starts with the environment's default limits (none in production), written on
 * the site — never merged at runtime, so sites that existed before (an upgrade, a backfill) or move later keep exactly
 * the limits they have. A compose project gets them as '*' (each service without its own).
 */
final class ApplyLimitDefaults
{
    /** Only a site this fresh is new (links of older sites are backfills or moves). */
    private const NEW_SITE_MINUTES = 10;

    public function __construct(
        private readonly LimitDefaults $defaults,
        private readonly LimitValidator $validator,
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
    ) {}

    public function handle(ServiceLinked $event): void
    {
        if ($event->kind !== ServiceKind::Site->value) {
            return;
        }

        $site = Site::query()->with('targets')->where('organization_id', $event->organizationId)->find($event->refId);

        if ($site === null || $site->limits !== null || $site->compose_limits !== null || $site->created_at->lt(now()->subMinutes(self::NEW_SITE_MINUTES))) {
            return;
        }

        $defaults = $this->defaults->forSite($site->id);

        // FrankenPHP sites run in the shared edge: only what applies to their processes.
        if ($site->runtime === SiteRuntime::FrankenPhp) {
            $defaults = ResourceLimits::fromArray(array_intersect_key($defaults->toArray(), array_flip(['restart_policy', 'max_restarts', 'log_max_size', 'log_max_files', 'oom'])));
        }

        if ($defaults->isEmpty() || in_array($site->runtime, [SiteRuntime::Static, SiteRuntime::Function], true)) {
            return;
        }

        try {
            $limits = $this->validator->validate($defaults->toArray(), $site->serverIds());
        } catch (ValidationException $e) {
            // Defaults a server can't hold (a small server): the site starts without them rather than unschedulable.
            Log::warning('limits: environment defaults do not fit the site\'s servers', ['site_id' => $site->id, 'errors' => $e->errors()]);

            return;
        }

        $site->forceFill($site->runtime === SiteRuntime::Compose ? ['compose_limits' => ['*' => $limits->toArray()]] : ['limits' => $limits->toArray()])->save();

        // Processes converges the slices; a pool already provisioned moves into its own master in the site's slice.
        SiteUpdated::dispatch($site->id, $site->organization_id, [$site->runtime === SiteRuntime::Compose ? 'compose_limits' : 'limits'], $site->serverIds());

        if ($site->runtime !== SiteRuntime::PhpFpm || ! $site->php_version) {
            return;
        }

        foreach ($site->targets as $target) {
            /** @var SiteTarget $target */
            if ($target->status !== TargetStatus::Ready) {
                continue;
            }

            try {
                $this->agents->dispatch($target->server_id, 'runtime.fpm.pool', CommandPayloads::fpmPool($site, $site->php_version, $this->servers->phpSettings($target->server_id, $site->php_version), limits: $limits),
                    300, "sites.pool.limits:{$site->id}:{$target->server_id}:".Str::ulid());
            } catch (AgentUnavailable) {
                // Re-sent with the pool when the target is provisioned again.
            }
        }
    }
}
