<?php

namespace Kiln\Edge\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Edge\Domain\Models\DnsCredential;
use Kiln\Identity\Events\OrganizationDeleted;

final class ForgetDeletedOrganization implements ShouldQueue
{
    public function handle(OrganizationDeleted $event): void
    {
        DnsCredential::query()->where('organization_id', $event->organizationId)->delete();
    }
}
