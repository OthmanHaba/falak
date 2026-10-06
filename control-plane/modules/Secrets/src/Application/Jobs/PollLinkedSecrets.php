<?php

namespace Falak\Secrets\Application\Jobs;

use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Every minute: queues one {@see PollLinkedSecret} per watched linked secret that is due (oldest first), at most
 * PER_ORGANIZATION per organization and BATCH in all per run, so one organization's watches can't crowd out the
 * others'. A queued secret's next poll moves forward at once, so the next run doesn't queue it again.
 */
final class PollLinkedSecrets implements ShouldQueue
{
    use Queueable;

    private const BATCH = 200;

    private const PER_ORGANIZATION = 25;

    public function handle(): void
    {
        $due = Secret::query()
            ->where('kind', SecretKind::Linked->value)
            ->whereNotNull('watch_minutes')
            ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->orderBy('next_poll_at')
            ->limit(self::BATCH * 5)
            ->get(['id', 'organization_id', 'watch_minutes']);

        $queued = 0;
        $perOrganization = [];

        foreach ($due as $secret) {
            if ($queued >= self::BATCH || ($perOrganization[$secret->organization_id] ?? 0) >= self::PER_ORGANIZATION) {
                continue;
            }

            Secret::query()->whereKey($secret->id)->update(['next_poll_at' => now()->addMinutes(max(1, (int) $secret->watch_minutes))]);
            PollLinkedSecret::dispatch($secret->id, $secret->organization_id);

            $perOrganization[$secret->organization_id] = ($perOrganization[$secret->organization_id] ?? 0) + 1;
            $queued++;
        }
    }
}
