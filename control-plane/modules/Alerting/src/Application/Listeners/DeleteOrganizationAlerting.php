<?php

namespace Falak\Alerting\Application\Listeners;

use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\Condition;
use Falak\Alerting\Domain\Models\DedupState;
use Falak\Alerting\Domain\Models\Notification;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Alerting\Domain\Models\RulePack;
use Falak\Identity\Events\OrganizationDeleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class DeleteOrganizationAlerting implements ShouldQueue
{
    public function handle(OrganizationDeleted $event): void
    {
        DB::transaction(function () use ($event) {
            Notification::query()->where('organization_id', $event->organizationId)->delete();
            Alert::query()->where('organization_id', $event->organizationId)->delete();
            DedupState::query()->where('organization_id', $event->organizationId)->delete();
            Condition::query()->where('organization_id', $event->organizationId)->delete();
            RulePack::query()->where('organization_id', $event->organizationId)->delete();
            Rule::query()->where('organization_id', $event->organizationId)->delete();
            Channel::query()->where('organization_id', $event->organizationId)->delete();
        });
    }
}
