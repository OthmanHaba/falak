<?php

namespace Falak\Alerting\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\DedupState;
use Falak\Alerting\Domain\Models\Notification;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Identity\Events\OrganizationDeleted;

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
