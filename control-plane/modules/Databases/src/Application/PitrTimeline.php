<?php

namespace Falak\Databases\Application;

use Carbon\CarbonImmutable;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\PitrGap;
use Falak\Databases\Domain\Models\PitrSegment;
use Illuminate\Support\Collection;

/**
 * The points an instance can be recovered to. A base backup restores to any time from its end (base_finished_at) to
 * the end of the last segment shipped after it, unless a gap in the log chain (PitrGap) comes first: recovery can't
 * cross it, so the range ends with the last segment before the gap, and the next base starts a new one.
 *
 * Times are the server's clock (falak-db's base times, the spool files' times); the UI shows them in UTC.
 */
final class PitrTimeline
{
    /** Segments are taken from a base's start on, with this much slack for the second-precision base times. */
    private const SLACK_SECONDS = 1;

    /**
     * @return array{ranges: list<array{from: string, to: string, base_id: string}>, gaps: list<array{at: string, detail: string, resolved: bool}>, from: ?string, to: ?string, last_segment_at: ?string}
     */
    public function for(DatabaseInstance $instance): array
    {
        $segments = $this->segments($instance);
        $gaps = PitrGap::query()->where('database_instance_id', $instance->id)->orderBy('detected_at')->get();
        $ranges = [];

        foreach ($this->bases($instance) as $base) {
            if (($range = $this->range($base, $segments, $gaps)) !== null) {
                $ranges[] = $range;
            }
        }

        $merged = [];

        foreach (collect($ranges)->sortBy(fn (array $range) => $range['from']->getTimestamp())->values() as $range) {
            $last = array_key_last($merged);

            if ($last !== null && $range['from'] <= $merged[$last]['to']) {
                if ($range['to'] > $merged[$last]['to']) {
                    $merged[$last]['to'] = $range['to'];
                    $merged[$last]['base_id'] = $range['base_id'];
                }

                continue;
            }

            $merged[] = $range;
        }

        $last = $segments->last();

        return [
            'ranges' => array_map(fn (array $range) => ['from' => self::iso($range['from']), 'to' => self::iso($range['to']), 'base_id' => $range['base_id']], $merged),
            'gaps' => $gaps->map(fn (PitrGap $gap) => ['at' => self::iso(CarbonImmutable::instance($gap->detected_at)), 'detail' => $gap->detail, 'resolved' => $gap->resolved_at !== null])->values()->all(),
            'from' => $merged !== [] ? self::iso($merged[0]['from']) : null,
            'to' => $merged !== [] ? self::iso($merged[count($merged) - 1]['to']) : null,
            'last_segment_at' => $last?->end_time !== null ? self::iso(CarbonImmutable::instance($last->end_time)) : null,
        ];
    }

    /**
     * What a restore to $target needs: the newest base it can start from and the segments to replay, from the base on
     * up to the first one ending at or after the target. Null when the target is not in a recovery range.
     *
     * @return ?array{base: Backup, segments: list<PitrSegment>}
     */
    public function plan(DatabaseInstance $instance, CarbonImmutable $target): ?array
    {
        $segments = $this->segments($instance);
        $gaps = PitrGap::query()->where('database_instance_id', $instance->id)->orderBy('detected_at')->get();

        foreach ($this->bases($instance)->reverse() as $base) {
            $range = $this->range($base, $segments, $gaps);

            if ($range === null || $target < $range['from'] || $target > $range['to']) {
                continue;
            }

            $chosen = [];

            foreach ($this->after($instance->engine === Engine::PostgreSql, $base, $segments) as $segment) {
                $chosen[$segment->name] = $segment;

                if (! str_ends_with($segment->name, '.history') && $segment->end_time !== null && CarbonImmutable::instance($segment->end_time) >= $target) {
                    break;
                }
            }

            return ['base' => $base, 'segments' => array_values($chosen)];
        }

        return null;
    }

    /**
     * Successful, unpruned bases, oldest first.
     *
     * @return Collection<int, Backup>
     */
    public function bases(DatabaseInstance $instance): Collection
    {
        return Backup::query()->where('database_instance_id', $instance->id)->where('type', Backup::BASE)
            ->where('status', BackupStatus::Succeeded)->whereNotNull('base_finished_at')
            ->orderBy('base_finished_at')->get();
    }

    /**
     * Shipped segments of the instance's log kind, in the order they were spooled.
     *
     * @return Collection<int, PitrSegment>
     */
    public function segments(DatabaseInstance $instance): Collection
    {
        return PitrSegment::query()->where('database_instance_id', $instance->id)->where('kind', self::kind($instance))
            ->whereNotNull('shipped_at')->whereNotNull('end_time')
            ->orderBy('end_time')->orderBy('name')->get();
    }

    public static function kind(DatabaseInstance $instance): string
    {
        return $instance->engine === Engine::PostgreSql ? 'wal' : 'binlog';
    }

    /**
     * The segments a base needs, in replay order: spooled after it started (postgres: from its start_wal on, and every
     * timeline history file). A name shipped twice (binlogs numbered again after a reset) counts once, the later one.
     *
     * @param  Collection<int, PitrSegment>  $segments
     * @return Collection<int, PitrSegment>
     */
    private function after(bool $postgres, Backup $base, Collection $segments): Collection
    {
        $start = CarbonImmutable::instance($base->base_started_at ?? $base->base_finished_at)->subSeconds(self::SLACK_SECONDS);

        return $segments
            ->filter(fn (PitrSegment $segment) => ($postgres && str_ends_with($segment->name, '.history'))
                || (CarbonImmutable::instance($segment->end_time) >= $start && (! $postgres || $base->log_start === null || strcmp($segment->name, $base->log_start) >= 0)))
            ->keyBy('name')
            ->sortBy(fn (PitrSegment $segment) => [$segment->end_time->getTimestamp(), $segment->name])
            ->values();
    }

    /**
     * @param  Collection<int, PitrSegment>  $segments
     * @param  Collection<int, PitrGap>  $gaps
     * @return ?array{from: CarbonImmutable, to: CarbonImmutable, base_id: string}
     */
    private function range(Backup $base, Collection $segments, Collection $gaps): ?array
    {
        $from = CarbonImmutable::instance($base->base_finished_at);
        $start = CarbonImmutable::instance($base->base_started_at ?? $base->base_finished_at);
        // The first gap after the base started ends what it can reach.
        $gap = $gaps->first(fn (PitrGap $gap) => CarbonImmutable::instance($gap->detected_at) > $start);
        $to = null;

        foreach ($this->after($base->engine === Engine::PostgreSql, $base, $segments) as $segment) {
            $end = CarbonImmutable::instance($segment->end_time);

            if ($gap !== null && $end > CarbonImmutable::instance($gap->detected_at)) {
                break;
            }

            $to = $to === null || $end > $to ? $end : $to;
        }

        return $to !== null && $to > $from ? ['from' => $from, 'to' => $to, 'base_id' => $base->id] : null;
    }

    private static function iso(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
