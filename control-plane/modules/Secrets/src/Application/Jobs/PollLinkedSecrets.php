<?php

namespace Falak\Secrets\Application\Jobs;

use Falak\Secrets\Application\LinkedSecretWatch;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Every minute: polls the watched linked secrets that are due (oldest first, a bounded batch per run).
 */
final class PollLinkedSecrets implements ShouldQueue
{
    use Queueable;

    private const BATCH = 200;

    public function handle(LinkedSecretWatch $watch): void
    {
        $due = Secret::query()
            ->where('kind', SecretKind::Linked->value)
            ->whereNotNull('watch_minutes')
            ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->orderBy('next_poll_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($due as $secret) {
            try {
                $watch->poll($secret);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
