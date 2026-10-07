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
 * Who uses a database container, or how servers reach each other, changed: the private addresses its host port is
 * published on follow (InstanceNetwork::desiredAddresses), re-applied only when they differ from what was last sent.
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

            if ($desired === array_values((array) ($instance->published_addresses ?? []))) {
                continue;
            }

            $instance->forceFill(['published_addresses' => $desired !== [] ? $desired : null])->save();
            ($this->apply)($instance, background: true);
        }
    }
}
