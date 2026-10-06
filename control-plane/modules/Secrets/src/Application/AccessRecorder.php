<?php

namespace Falak\Secrets\Application;

use Falak\Secrets\Contracts\AccessorType;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Domain\Models\AccessLogEntry;
use Falak\Secrets\Domain\Models\Secret;

/**
 * Writes the secret access log. A deployment resolves its variables once per step; it is logged once per
 * secret version.
 */
final class AccessRecorder
{
    public function record(Secret $secret, int $version, SecretAccessor $accessor): void
    {
        if ($accessor->type === AccessorType::Deployment && AccessLogEntry::query()
            ->where('secret_id', $secret->id)->where('version', $version)
            ->where('actor_type', AccessorType::Deployment->value)->where('actor_id', $accessor->id)
            ->exists()) {
            return;
        }

        AccessLogEntry::query()->create([
            'organization_id' => $secret->organization_id,
            'secret_id' => $secret->id,
            'secret_name' => $secret->name,
            'version' => $version,
            'actor_type' => $accessor->type,
            'actor_id' => $accessor->id,
            'user_id' => $accessor->userId,
            'reason' => mb_substr($accessor->reason, 0, 255),
            'ip' => $accessor->ip,
            'created_at' => now(),
        ]);

        // Not a change of the secret: leave updated_at alone.
        Secret::query()->whereKey($secret->id)->toBase()->update(['last_accessed_at' => now()]);
    }
}
