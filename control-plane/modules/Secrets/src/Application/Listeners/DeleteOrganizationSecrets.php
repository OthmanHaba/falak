<?php

namespace Falak\Secrets\Application\Listeners;

use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\KeyRing;
use Falak\Secrets\Domain\Models\AccessLogEntry;
use Falak\Secrets\Domain\Models\ProviderValue;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * An organization's secrets, providers (and their cached values), access log and data key go with it.
 */
final class DeleteOrganizationSecrets implements ShouldQueue
{
    public function __construct(private readonly KeyRing $keys) {}

    public function handle(OrganizationDeleted $event): void
    {
        DB::transaction(function () use ($event) {
            $ids = Secret::query()->where('organization_id', $event->organizationId)->pluck('id');

            SecretVersion::query()->whereIn('secret_id', $ids)->delete();
            Secret::query()->whereIn('id', $ids)->delete();
            AccessLogEntry::query()->where('organization_id', $event->organizationId)->delete();
            ProviderValue::query()->where('organization_id', $event->organizationId)->delete();
            SecretProvider::query()->where('organization_id', $event->organizationId)->delete();

            $this->keys->destroy(DataKey::organization($event->organizationId));
        });
    }
}
