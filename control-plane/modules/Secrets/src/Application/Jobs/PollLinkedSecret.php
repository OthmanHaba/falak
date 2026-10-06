<?php

namespace Falak\Secrets\Application\Jobs;

use Falak\Secrets\Application\LinkedSecretWatch;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Poll one watched linked secret. Unique per secret (the lock outlives the job's timeout), and at most
 * secrets.providers.poll_concurrency_per_organization of one organization run at once: the others are released
 * back to the queue for a few seconds.
 */
final class PollLinkedSecret implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    /** Released while the organization's slots are busy: retried for up to ten minutes. */
    public int $tries = 0;

    public int $maxExceptions = 1;

    /** Longer than $timeout: a poll still running is never doubled. */
    public int $uniqueFor = 600;

    public function __construct(
        public string $secretId,
        public string $organizationId,
    ) {}

    public function uniqueId(): string
    {
        return $this->secretId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(LinkedSecretWatch $watch): void
    {
        $slot = self::acquireSlot($this->organizationId, $this->timeout + 30);

        if ($slot === null) {
            $this->release(5);

            return;
        }

        try {
            $secret = Secret::query()->find($this->secretId);

            if ($secret !== null) {
                $watch->poll($secret);
            }
        } finally {
            $slot->release();
        }
    }

    /**
     * One of the organization's concurrency slots (a cache lock), or null when they are all taken.
     */
    public static function acquireSlot(string $organizationId, int $seconds): ?Lock
    {
        $slots = max(1, (int) config('secrets.providers.poll_concurrency_per_organization', 3));

        for ($i = 0; $i < $slots; $i++) {
            $lock = Cache::lock("secrets:poll:{$organizationId}:{$i}", $seconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
