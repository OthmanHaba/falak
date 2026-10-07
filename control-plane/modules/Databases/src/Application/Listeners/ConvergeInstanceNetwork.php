<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Application\Actions\ApplyInstance;
use Falak\Databases\Application\InstanceNetwork;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Projects\Events\ServiceLinked;
use Falak\Projects\Events\ServiceUnlinked;
use Falak\Sites\Events\SiteTargetsChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Who uses a database container, or how servers reach each other, changed. New private addresses for its host port
 * (InstanceNetwork::desiredAddresses) wait as pending until someone applies them, since that recreates the container;
 * the firewall's allowed sources follow right away (no restart).
 */
final class ConvergeInstanceNetwork implements ShouldQueue
{
    public function __construct(
        private readonly InstanceNetwork $network,
        private readonly ApplyInstance $apply,
    ) {}

    public function serviceLinked(ServiceLinked $event): void
    {
        $this->converge($event->organizationId);
    }

    public function serviceUnlinked(ServiceUnlinked $event): void
    {
        $this->converge($event->organizationId);
    }

    public function siteTargetsChanged(SiteTargetsChanged $event): void
    {
        $this->converge($event->organizationId);
    }

    public function privateNetworkChanged(PrivateNetworkChanged $event): void
    {
        $this->converge($event->organizationId);
    }

    public function converge(string $organizationId): void
    {
        $instances = DatabaseInstance::query()->where('organization_id', $organizationId)->where('status', InstanceStatus::Active)->get();

        foreach ($instances as $instance) {
            $desired = $this->network->desiredAddresses($instance);

            if ($desired !== array_values((array) ($instance->published_addresses ?? []))) {
                // New addresses need a new container (Docker binds ports at creation): never silently. They wait for
                // someone to apply them (the instance page says a restart is required).
                $instance->forceFill(['pending_published_addresses' => $desired])->save();

                continue;
            }

            if ($instance->pending_published_addresses !== null) {
                $instance->forceFill(['pending_published_addresses' => null])->save();
            }

            // Who may connect changes live (the agent's firewall rules), no restart.
            if (($instance->published_addresses ?? []) !== [] && $this->network->allowedSources($instance) !== array_values((array) ($instance->firewall_sources ?? []))) {
                ($this->apply)($instance, background: true);
            }
        }
    }
}
