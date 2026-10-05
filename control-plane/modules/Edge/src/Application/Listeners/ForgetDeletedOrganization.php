<?php

namespace Falak\Edge\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Identity\Events\OrganizationDeleted;

final class ForgetDeletedOrganization implements ShouldQueue
{
    public function handle(OrganizationDeleted $event): void
    {
        DnsCredential::query()->where('organization_id', $event->organizationId)->delete();
    }
}
