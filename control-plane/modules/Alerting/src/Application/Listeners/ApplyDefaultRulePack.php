<?php

namespace Falak\Alerting\Application\Listeners;

use Falak\Alerting\Application\DefaultRulePack;
use Falak\Identity\Events\OrganizationCreated;

/**
 * A new organization starts with the default rule pack (in-app notifications until it adds a channel).
 */
final class ApplyDefaultRulePack
{
    public function __construct(private readonly DefaultRulePack $pack) {}

    public function handle(OrganizationCreated $event): void
    {
        $this->pack->apply($event->organizationId);
    }
}
