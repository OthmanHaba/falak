<?php

namespace Falak\Servers\Application\Actions;

use Falak\Servers\Application\MachineChecks;
use Falak\Servers\Domain\Models\MachineInspection;
use Falak\Servers\Domain\Models\Server;

/**
 * Starts provisioning: the machine check first (agents with provision.v2), which applies the plan once nothing blocks;
 * older agents get the plan directly, as before.
 */
final class ProvisionServer
{
    public function __construct(
        private readonly MachineChecks $checks,
        private readonly RunMachineCheck $check,
        private readonly ApplyProvisioningPlan $apply,
    ) {}

    /**
     * @return string the provision.inspect or provision.apply command id ('' when the agent is not enrolled)
     */
    public function __invoke(Server $server, ?string $actorId = null): string
    {
        if ($this->checks->supported($server)) {
            return ($this->check)($server, MachineInspection::PURPOSE_PROVISION, $actorId);
        }

        return ($this->apply)($server);
    }
}
