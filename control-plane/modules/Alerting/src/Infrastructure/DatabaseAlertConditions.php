<?php

namespace Falak\Alerting\Infrastructure;

use Closure;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Models\Condition;
use Falak\Alerting\Domain\Models\DedupState;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseAlertConditions implements AlertConditions
{
    /** How often a condition that keeps holding refreshes seen_at (pruning forgets the unobserved ones). */
    private const TOUCH_SECONDS = 3600;

    public function __construct(private readonly Alerts $alerts) {}

    public function observe(string $organizationId, string $key, ?bool $holds, Closure $alert, ?Closure $recovery = null, int $forSeconds = 0, bool $announceRecovery = true): void
    {
        match ($holds) {
            true => $this->hold($organizationId, $key, $alert, $forSeconds),
            false => $this->clear($organizationId, $key, $recovery, $announceRecovery),
            null => $this->touch($organizationId, $key),
        };
    }

    public function clearExcept(string $organizationId, string $prefix, array $keep = []): void
    {
        // A prefix match on the (organization_id, key) index; "!" escapes LIKE's wildcards, which keys may hold.
        $query = Condition::query();
        $column = $query->getQuery()->getGrammar()->wrap('key');
        $stale = $query->where('organization_id', $organizationId)
            ->whereRaw("{$column} like ? escape '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%'])
            ->when($keep !== [], fn ($q) => $q->whereNotIn('key', $keep))
            ->pluck('key');

        foreach ($stale as $key) {
            $this->clear($organizationId, (string) $key, null);
        }
    }

    /** Between the thresholds (hysteresis): no change, but the condition is still observed (pruning). */
    private function touch(string $organizationId, string $key): void
    {
        Condition::query()->where('organization_id', $organizationId)->where('key', $key)
            ->where('seen_at', '<', now()->subSeconds(self::TOUCH_SECONDS))->update(['seen_at' => now()]);
    }

    /**
     * @param  Closure(): AlertData  $alert
     */
    private function hold(string $organizationId, string $key, Closure $alert, int $forSeconds): void
    {
        $now = now();
        $condition = Condition::query()->where('organization_id', $organizationId)->where('key', $key)->first();

        if ($condition === null) {
            try {
                $condition = Condition::query()->create(['organization_id' => $organizationId, 'key' => $key, 'since' => $now, 'seen_at' => $now]);
            } catch (UniqueConstraintViolationException) {
                return; // a concurrent check observed it first
            }
        } elseif ($condition->seen_at->diffInSeconds($now) >= self::TOUCH_SECONDS) {
            $condition->forceFill(['seen_at' => $now])->save();
        }

        if ($condition->raised_at !== null || $condition->since->diffInSeconds($now) < $forSeconds) {
            return;
        }

        // Claim the raise: only one of two concurrent checks raises.
        if (Condition::query()->whereKey($condition->id)->whereNull('raised_at')->update(['raised_at' => $now, 'seen_at' => $now]) !== 1) {
            return;
        }

        $data = self::withKey($alert(), $key);
        Condition::query()->whereKey($condition->id)->update(['type' => $data->type, 'title' => Str::limit($data->title, 490), 'url' => $data->url]);

        $this->alerts->raise($data);
    }

    /**
     * @param  (Closure(): AlertData)|null  $recovery
     */
    private function clear(string $organizationId, string $key, ?Closure $recovery, bool $announce = true): void
    {
        // Most observations clear nothing: a plain indexed lookup before the locking read.
        if (! Condition::query()->where('organization_id', $organizationId)->where('key', $key)->exists()) {
            return;
        }

        $condition = DB::transaction(function () use ($organizationId, $key) {
            $condition = Condition::query()->where('organization_id', $organizationId)->where('key', $key)->lockForUpdate()->first();
            $condition?->delete();

            return $condition;
        });

        if ($condition?->raised_at === null) {
            return;
        }

        if (! $announce) {
            self::release($organizationId, $key);

            return;
        }

        $data = $recovery !== null ? $recovery() : new AlertData(
            $organizationId,
            (string) $condition->type,
            Severity::Info,
            (string) $condition->title,
            'This no longer holds.',
            $condition->url,
        );

        $this->alerts->raise(self::withKey($data, $key, resolves: true));
    }

    /**
     * Conditions nobody observed for $days (their server, certificate or schedule is gone): forgotten, and their dedup
     * keys released without a recovery.
     */
    public static function prune(int $days = 7): void
    {
        Condition::query()->where('seen_at', '<', now()->subDays($days))->chunkById(500, function ($conditions) {
            foreach ($conditions as $condition) {
                self::release($condition->organization_id, $condition->key);
                $condition->delete();
            }
        });
    }

    /** Ends the alert episode of $key without a recovery: the next time it holds, it alerts again. */
    private static function release(string $organizationId, string $key): void
    {
        DedupState::query()->where('organization_id', $organizationId)->where('dedup_key', $key)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    private static function withKey(AlertData $data, string $key, bool $resolves = false): AlertData
    {
        return new AlertData(
            $data->organizationId,
            $data->type,
            $data->severity,
            $data->title,
            $data->body,
            $data->url,
            $key,
            $resolves,
            $data->context,
            $data->action,
            $data->detail,
        );
    }
}
