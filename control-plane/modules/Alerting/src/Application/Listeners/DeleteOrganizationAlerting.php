<?php

namespace Kiln\Alerting\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Kiln\Alerting\Domain\Models\Alert;
use Kiln\Alerting\Domain\Models\Channel;
use Kiln\Alerting\Domain\Models\DedupState;
use Kiln\Alerting\Domain\Models\Notification;
use Kiln\Alerting\Domain\Models\Rule;
use Kiln\Identity\Events\OrganizationDeleted;

final class DeleteOrganizationAlerting implements ShouldQueue
{
    public function handle(OrganizationDeleted $event): void
    {
        DB::transaction(function () use ($event) {
            Notification::query()->where('organization_id', $event->organizationId)->delete();
            Alert::query()->where('organization_id', $event->organizationId)->delete();
            DedupState::query()->where('organization_id', $event->organizationId)->delete();
            Rule::query()->where('organization_id', $event->organizationId)->delete();
            Channel::query()->where('organization_id', $event->organizationId)->delete();
        });
    }
}
