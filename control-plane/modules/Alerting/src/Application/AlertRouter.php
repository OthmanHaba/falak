<?php

namespace Falak\Alerting\Application;

use Falak\Alerting\Application\Jobs\DeliverAlert;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Domain\Enums\AlertOutcome;
use Falak\Alerting\Domain\Enums\DeliveryStatus;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\DedupState;
use Falak\Alerting\Domain\Models\Delivery;
use Falak\Alerting\Domain\Models\Rule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Routes one alert: dedup → matching rules → quiet hours / rate limits → channel deliveries +
 * in-app notifications. Every alert is recorded with its outcome (alert history).
 *
 * Deduplication: an alert with a dedup key claims the key; later alerts with the same key are
 * recorded as deduplicated until a resolving alert (AlertData::$resolves) clears it. A recovery
 * is delivered to the channels that received the original alert plus rules matching its type.
 */
final class AlertRouter
{
    public function __construct(private readonly InAppNotifier $notifier) {}

    public function route(AlertData $data): Alert
    {
        return $data->resolves ? $this->recover($data) : $this->alert($data);
    }

    private function alert(AlertData $data): Alert
    {
        $now = now();
        $rules = $this->matchingRules($data);

        if ($rules->isEmpty()) {
            return $this->record($data, AlertOutcome::NoRoute);
        }

        [$passing, $quiet, $limited] = $this->filter($rules, $data, $now);

        if ($passing->isEmpty() && $quiet->isEmpty()) {
            $outcome = $data->dedupKey && $this->activeState($data) ? AlertOutcome::Deduplicated : AlertOutcome::RateLimited;

            return $this->record($data, $outcome, $rules->modelKeys());
        }

        $alertId = (string) Str::ulid();

        if ($data->dedupKey !== null && ! $this->claim($data, $alertId, $now)) {
            return $this->record($data, AlertOutcome::Deduplicated, $rules->modelKeys());
        }

        $outcome = $passing->isNotEmpty() ? AlertOutcome::Delivered : AlertOutcome::QuietHours;

        return $this->dispatch($data, $alertId, $outcome, $rules, $passing, $this->channelsOf($passing));
    }

    private function recover(AlertData $data): Alert
    {
        $state = $data->dedupKey === null ? null : DB::transaction(function () use ($data) {
            $state = DedupState::query()
                ->where('organization_id', $data->organizationId)
                ->where('dedup_key', $data->dedupKey)
                ->lockForUpdate()
                ->first();

            if (! $state || ! $state->isActive()) {
                return null;
            }

            $state->forceFill(['resolved_at' => now(), 'last_seen_at' => now()])->save();

            return $state;
        });

        if (! $state) {
            return $this->record($data, AlertOutcome::RecoverySkipped);
        }

        $rules = $this->matchingRules($data);
        [$passing] = $this->filter($rules, $data, now());

        $originalChannels = Channel::query()
            ->where('organization_id', $data->organizationId)
            ->where('enabled', true)
            ->whereIn('id', Delivery::query()->where('alert_id', $state->alert_id)->select('channel_id'))
            ->get();

        $channels = $originalChannels->merge($this->channelsOf($passing))->unique('id')->values();

        return $this->dispatch($data, (string) Str::ulid(), AlertOutcome::Delivered, $rules, $passing, new Collection($channels->all()));
    }

    /**
     * @param  Collection<int, Rule>  $matched
     * @param  Collection<int, Rule>  $passing
     * @param  Collection<int, Channel>  $channels
     */
    private function dispatch(AlertData $data, string $alertId, AlertOutcome $outcome, Collection $matched, Collection $passing, Collection $channels): Alert
    {
        [$alert, $deliveries] = DB::transaction(function () use ($data, $alertId, $outcome, $matched, $passing, $channels) {
            $alert = $this->record($data, $outcome, $matched->modelKeys(), $alertId);

            foreach ($passing as $rule) {
                DB::table('alerting_rule_hits')->insert(['rule_id' => $rule->id, 'alert_id' => $alert->id, 'created_at' => $alert->created_at]);
            }

            $deliveries = $channels->map(fn (Channel $channel) => Delivery::query()->create([
                'alert_id' => $alert->id,
                'channel_id' => $channel->id,
                'status' => DeliveryStatus::Pending,
            ]));

            return [$alert, $deliveries];
        });

        foreach ($deliveries as $delivery) {
            DeliverAlert::dispatch($delivery->id)->onQueue((string) config('alerting.queue', 'default'));
        }

        // In-app notifications ignore quiet hours (they are silent) but respect dedup and rate limits.
        $this->notifier->notify($alert);

        return $alert;
    }

    /**
     * @return Collection<int, Rule>
     */
    private function matchingRules(AlertData $data): Collection
    {
        return Rule::query()
            ->with(['channels' => fn ($query) => $query->where('enabled', true)])
            ->where('organization_id', $data->organizationId)
            ->where('enabled', true)
            ->get()
            ->filter(fn (Rule $rule) => $rule->matches($data->type, $data->severity))
            ->values();
    }

    /**
     * @param  Collection<int, Rule>  $rules
     * @return array{0: Collection<int, Rule>, 1: Collection<int, Rule>, 2: Collection<int, Rule>} [passing, quiet, rate limited]
     */
    private function filter(Collection $rules, AlertData $data, Carbon $now): array
    {
        $passing = new Collection;
        $quiet = new Collection;
        $limited = new Collection;

        foreach ($rules as $rule) {
            if ($rule->quietHours()?->suppresses($data->severity, $now)) {
                $quiet->push($rule);
            } elseif ($rule->rate_limit_per_hour !== null && $this->hitsInLastHour($rule, $now) >= $rule->rate_limit_per_hour) {
                $limited->push($rule);
            } else {
                $passing->push($rule);
            }
        }

        return [$passing, $quiet, $limited];
    }

    private function hitsInLastHour(Rule $rule, Carbon $now): int
    {
        return DB::table('alerting_rule_hits')
            ->where('rule_id', $rule->id)
            ->where('created_at', '>', $now->copy()->subHour())
            ->count();
    }

    /**
     * @param  Collection<int, Rule>  $rules
     * @return Collection<int, Channel>
     */
    private function channelsOf(Collection $rules): Collection
    {
        return new Collection($rules->flatMap(fn (Rule $rule) => $rule->channels)->unique('id')->values()->all());
    }

    private function activeState(AlertData $data): bool
    {
        return DedupState::query()
            ->where('organization_id', $data->organizationId)
            ->where('dedup_key', $data->dedupKey)
            ->whereNull('resolved_at')
            ->exists();
    }

    /**
     * Claim the dedup key for a new alert episode. False when an unresolved episode exists
     * (its occurrence counter is bumped instead).
     */
    private function claim(AlertData $data, string $alertId, Carbon $now): bool
    {
        try {
            return DB::transaction(function () use ($data, $alertId, $now) {
                $state = DedupState::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('dedup_key', $data->dedupKey)
                    ->lockForUpdate()
                    ->first();

                if ($state && $state->isActive()) {
                    $state->forceFill(['occurrences' => $state->occurrences + 1, 'last_seen_at' => $now])->save();

                    return false;
                }

                $attributes = ['alert_id' => $alertId, 'first_alerted_at' => $now, 'last_seen_at' => $now, 'occurrences' => 1, 'resolved_at' => null];

                $state
                    ? $state->forceFill($attributes)->save()
                    : DedupState::query()->create(['organization_id' => $data->organizationId, 'dedup_key' => $data->dedupKey, ...$attributes]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker claimed the key first.
            return false;
        }
    }

    /**
     * @param  list<string>  $ruleIds
     */
    private function record(AlertData $data, AlertOutcome $outcome, array $ruleIds = [], ?string $id = null): Alert
    {
        return Alert::query()->create([
            'id' => $id ?? (string) Str::ulid(),
            'organization_id' => $data->organizationId,
            'type' => $data->type,
            'severity' => $data->severity,
            'title' => Str::limit($data->title, 490),
            'body' => $data->body !== '' ? $data->body : null,
            'url' => self::absoluteUrl($data->url),
            'dedup_key' => $data->dedupKey,
            'recovery' => $data->resolves,
            'context' => $data->context === [] ? null : $data->context,
            'outcome' => $outcome,
            'matched_rule_ids' => $ruleIds === [] ? null : array_values($ruleIds),
        ]);
    }

    public static function absoluteUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : url($url);
    }
}
