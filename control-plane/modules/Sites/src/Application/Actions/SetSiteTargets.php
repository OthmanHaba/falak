<?php

namespace Kiln\Sites\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Application\OctanePorts;
use Kiln\Sites\Application\SiteRules;
use Kiln\Sites\Application\TargetProvisioner;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\Sites\Events\SiteTargetsChanged;

/**
 * Change the servers a site deploys to (the multi-server deployment group) and its leader.
 */
final class SetSiteTargets
{
    public function __construct(
        private readonly SiteRules $rules,
        private readonly TargetProvisioner $provisioner,
        private readonly AuditLog $audit,
        private readonly OctanePorts $octanePorts,
    ) {}

    /**
     * @param  list<string>  $serverIds
     */
    public function __invoke(Site $site, array $serverIds, string $leaderServerId): void
    {
        $serverIds = array_values(array_unique($serverIds));

        if (! in_array($leaderServerId, $serverIds, true)) {
            throw ValidationException::withMessages(['leader_server_id' => 'The leader must be one of the selected servers.']);
        }

        $site->loadMissing('targets');
        $current = $site->serverIds();
        $added = array_values(array_diff($serverIds, $current));
        $removed = array_values(array_diff($current, $serverIds));

        if ($added !== []) {
            $this->rules->targets($site->organization_id, $added, $site->runtime, $site->php_version, $site->build_mode);
        }

        if ($site->app_port !== null && $added !== []) {
            if ($site->runtime === SiteRuntime::Docker) {
                // A docker site's host port is Kiln's own: move it when a new server already uses it.
                if (in_array($site->app_port, $this->rules->portsInUse($added, $site->id), true)) {
                    $site->forceFill(['app_port' => $this->rules->freePort($serverIds, $site->id)])->save();
                }
            } else {
                $this->rules->portAvailable($site->app_port, $added, $site->id);
            }
        }

        $leaderChanged = $site->leaderTarget()?->server_id !== $leaderServerId;

        if ($added === [] && $removed === [] && ! $leaderChanged) {
            return;
        }

        $newTargets = DB::transaction(function () use ($site, $added, $removed, $leaderServerId) {
            $site->targets()->whereIn('server_id', $removed)->delete();
            $site->targets()->update(['role' => TargetRole::Member]);

            $created = [];

            foreach ($added as $serverId) {
                $created[] = SiteTarget::query()->create([
                    'site_id' => $site->id,
                    'server_id' => $serverId,
                    'role' => TargetRole::Member,
                    'status' => TargetStatus::Pending,
                ]);
            }

            $site->targets()->where('server_id', $leaderServerId)->update(['role' => TargetRole::Leader]);

            return $created;
        });

        foreach ($removed as $serverId) {
            if ($site->runtime === SiteRuntime::PhpFpm && $site->php_version) {
                $this->provisioner->removePool($site, $serverId, $site->php_version);
            }

            $this->provisioner->removeContainers($site, $serverId);
        }

        foreach ($newTargets as $target) {
            $target->setRelation('site', $site);
            $this->provisioner->start($target);
        }

        $site->load('targets');

        // The Octane port must stay free on the new servers too: move it when one of them already uses it.
        if ($added !== []) {
            $this->octanePorts->reassign($site);
        }

        $this->audit->record('site.targets_updated', 'site', $site->id, ['added' => $added, 'removed' => $removed, 'leader' => $leaderServerId], $site->organization_id);
        SiteTargetsChanged::dispatch($site->id, $site->organization_id, $added, $removed, $site->serverIds(), $leaderServerId);
    }
}
