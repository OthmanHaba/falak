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

    public function observe(string $organizationId, string $key, bool $holds, Closure $alert, ?Closure $recovery = null, int $forSeconds = 0): void
    {
        $holds ? $this->hold($organizationId, $key, $alert, $forSeconds) : $this->clear($organizationId, $key, $recovery);
    }

    public function clearExcept(string $organizationId, string $prefix, array $keep = []): void
    {
        $stale = Condition::query()->where('organization_id', $organizationId)
            ->where('key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $prefix).'%')
            ->whereNotIn('key', $keep)
            ->pluck('key');

        foreach ($stale as $key) {
            $this->clear($organizationId, (string) $key, null);
        }
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
    private function clear(string $organizationId, string $key, ?Closure $recovery): void
    {
        $condition = DB::transaction(function () use ($organizationId, $key) {
            $condition = Condition::query()->where('organization_id', $organizationId)->where('key', $key)->lockForUpdate()->first();
            $condition?->delete();

            return $condition;
        });

        if ($condition?->raised_at === null) {
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
                DedupState::query()->where('organization_id', $condition->organization_id)->where('dedup_key', $condition->key)
                    ->whereNull('resolved_at')->update(['resolved_at' => now()]);
                $condition->delete();
            }
        });
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
        );
    }
}
