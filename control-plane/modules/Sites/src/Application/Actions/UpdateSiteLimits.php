<?php

namespace Falak\Sites\Application\Actions;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Limits\Contracts\LimitDefaults;
use Falak\Limits\Contracts\LimitValidator;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteUpdated;
use Falak\Sites\Infrastructure\CommandPayloads;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sets the resource limits of a site, or of one service of its compose project, and applies them where they run:
 *
 *  - Docker sites and compose services: docker.update on the running containers when only live limits changed
 *    (memory, reservation, CPUs, processes, restart policy) and none was removed; otherwise the next deploy applies
 *    them (log caps and the OOM preference need a new container; a compose project's override is written per deploy).
 *  - Classic sites: their slice (Processes re-converges every server on SiteUpdated: set-property, live), and PHP-FPM
 *    sites moving into or out of their own master get their pool again.
 *
 * Returns how they apply: live, redeploy (on the next deploy) or none (nothing runs yet / nothing changed).
 */
final class UpdateSiteLimits
{
    public function __construct(
        private readonly LimitValidator $validator,
        private readonly LimitDefaults $defaults,
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
        private readonly ComposeSites $compose,
        private readonly ComposeInspector $inspector,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $input  the `limits` object (null or empty: no limits of its own)
     * @param  ?string  $service  a compose service of the site
     * @return 'live'|'redeploy'|'none'
     *
     * @throws ValidationException
     */
    public function __invoke(Site $site, ?array $input, ?string $service, ?string $userId): string
    {
        $site->loadMissing('targets');
        $this->allowed($site, $input ?? [], $service);
        $limits = $this->validator->validate($input, $site->serverIds());
        $before = $service !== null ? ResourceLimits::fromArray($site->compose_limits[$service] ?? null) : ResourceLimits::fromArray($site->limits);

        if ($before == $limits) {
            return 'none';
        }

        if ($service !== null) {
            $all = $site->compose_limits ?? [];
            unset($all[$service]);

            if (! $limits->isEmpty()) {
                $all[$service] = $limits->toArray();
            }

            ksort($all);
            $site->forceFill(['compose_limits' => $all === [] ? null : $all])->save();
        } else {
            $site->forceFill(['limits' => $limits->isEmpty() ? null : $limits->toArray()])->save();
        }

        $this->audit->record('site.limits_updated', 'site', $site->id, array_filter(['service' => $service, ...$limits->toArray()], fn ($v) => $v !== null), $site->organization_id);
        // Processes re-converges the site's servers (slices of the site, its workers' and daemons' programs).
        SiteUpdated::dispatch($site->id, $site->organization_id, [$service !== null ? 'compose_limits' : 'limits'], $site->serverIds());

        $ready = $site->targets->filter(fn (SiteTarget $target) => $target->status === TargetStatus::Ready);

        if ($ready->isEmpty()) {
            return 'none';
        }

        $old = $this->defaults->effective($before, $site->id);
        $new = $this->defaults->effective($limits, $site->id);

        return match (true) {
            $site->runtime === SiteRuntime::Docker || $service !== null => $this->updateContainers($site, $ready->all(), $old, $new, $service),
            $site->runtime === SiteRuntime::PhpFpm => $this->updatePool($site, $ready->all(), $old, $new),
            default => 'live',
        };
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function allowed(Site $site, array $input, ?string $service): void
    {
        if ($service !== null) {
            $names = $site->runtime === SiteRuntime::Compose ? $this->inspector->parse($this->compose->project($site->id) ?? '')->serviceNames() : [];

            if (! in_array($service, $names, true)) {
                throw ValidationException::withMessages(['service' => 'The compose project has no such service.']);
            }

            return;
        }

        $error = match (true) {
            $site->runtime === SiteRuntime::Compose => 'A compose project\'s limits are set per service.',
            $site->runtime === SiteRuntime::Static => 'Static sites are served by the edge and have nothing to limit.',
            $site->runtime === SiteRuntime::Function => 'Functions have their own memory and CPU settings.',
            // One FrankenPHP edge serves every FrankenPHP site: there is no per-site process to put limits on.
            $site->runtime === SiteRuntime::FrankenPhp && ResourceLimits::fromArray($input)->hasCgroupLimits() => 'FrankenPHP sites run inside the shared edge: limit memory and CPUs through Octane workers, or use PHP-FPM.',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['limits' => $error]);
        }
    }

    /**
     * @param  list<SiteTarget>  $targets
     */
    private function updateContainers(Site $site, array $targets, ResourceLimits $old, ResourceLimits $new, ?string $service): string
    {
        if (! $old->liveUpdatableTo($new) || $new->dockerUpdate() === []) {
            return 'redeploy';
        }

        $payload = [...($service !== null ? ['project' => $site->slug, 'service' => $service] : ['site' => $site->slug]), ...$new->dockerUpdate()];

        foreach ($targets as $target) {
            try {
                $this->agents->dispatch($target->server_id, 'docker.update', $payload, 60, "sites.limits:{$site->id}:{$target->server_id}:".Str::ulid());
            } catch (AgentUnavailable) {
                // Disconnected: the next deploy applies them.
            }
        }

        return 'live';
    }

    /**
     * Limits on or off move the pool between the shared PHP-FPM master and its own (a short restart); changed
     * limits only change the slice, live.
     *
     * @param  list<SiteTarget>  $targets
     */
    private function updatePool(Site $site, array $targets, ResourceLimits $old, ResourceLimits $new): string
    {
        if ($old->hasCgroupLimits() === $new->hasCgroupLimits() && $old->oomScoreAdj() === $new->oomScoreAdj()) {
            return 'live';
        }

        foreach ($targets as $target) {
            $payload = CommandPayloads::fpmPool($site, (string) $site->php_version, $this->servers->phpSettings($target->server_id, (string) $site->php_version), limits: $new);

            try {
                $this->agents->dispatch($target->server_id, 'runtime.fpm.pool', $payload, 300, "sites.pool.limits:{$site->id}:{$target->server_id}:".Str::ulid());
            } catch (AgentUnavailable) {
                // Re-sent with the pool on the next provisioning of the target.
            }
        }

        return 'live';
    }
}
