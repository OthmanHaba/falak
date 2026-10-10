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
 * the end of its **chain**: the segments that follow one another without a hole from the one the base starts in
 * (log_start), in the base's epoch (binlogs are numbered again after a reset). A missing segment ends the chain, and so
 * does a reported gap (PitrGap); the next base starts a new one.
 *
 *  - PostgreSQL: WAL segment names are <timeline><log><seg> (hex, 16 MiB segments: seg 00–FF); the next of a segment is
 *    the next number on the same timeline, or the same or next number on a newer timeline whose .history was shipped
 *    (a promotion).
 *  - MySQL / MariaDB: binlog.NNNNNN, the next number.
 *
 * Times are the server's clock (falak-db's base times, the segments' end times); the UI shows them in UTC.
 */
final class PitrTimeline
{
    /** Segments are taken from a base's start on, with this much slack for the second-precision base times. */
    private const SLACK_SECONDS = 1;

    /** The last segment number of a WAL "log" with 16 MiB segments. */
    private const LAST_SEG = 0xFF;

    /**
     * @return array{ranges: list<array{from: string, to: string, base_id: string}>, gaps: list<array{at: string, detail: string, resolved: bool}>, from: ?string, to: ?string, last_segment_at: ?string}
     */
    public function for(DatabaseInstance $instance): array
    {
        $segments = $this->segments($instance);
        $gaps = PitrGap::query()->where('database_instance_id', $instance->id)->orderBy('detected_at')->get();
        $ranges = [];
        $holes = [];

        foreach ($this->bases($instance) as $base) {
            $chain = $this->chain($base, $segments, $gaps);

            if (($range = self::range($base, $chain['segments'])) !== null) {
                $ranges[] = $range;
            }

            if ($chain['hole'] !== null) {
                $holes[$chain['hole']['detail']] = $chain['hole'];
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

        $last = $segments->sortBy(fn (PitrSegment $segment) => $segment->end_time->getTimestamp())->last();
        $reported = $gaps->map(fn (PitrGap $gap) => ['at' => self::iso(CarbonImmutable::instance($gap->detected_at)), 'detail' => $gap->detail, 'resolved' => $gap->resolved_at !== null]);

        return [
            'ranges' => array_map(fn (array $range) => ['from' => self::iso($range['from']), 'to' => self::iso($range['to']), 'base_id' => $range['base_id']], $merged),
            // Reported gaps and the holes found in the chains (a segment that never arrived).
            'gaps' => $reported->concat(array_values($holes))->sortBy('at')->values()->all(),
            'from' => $merged !== [] ? self::iso($merged[0]['from']) : null,
            'to' => $merged !== [] ? self::iso($merged[count($merged) - 1]['to']) : null,
            'last_segment_at' => $last?->end_time !== null ? self::iso(CarbonImmutable::instance($last->end_time)) : null,
        ];
    }

    /**
     * What a restore to $target needs: the newest base whose range holds it and its chain up to the first segment
     * ending at or after the target (timeline history files included). $target null: the latest point, the newest
     * range's whole chain (`latest` true; `to` is how far it reaches).
     *
     * @return ?array{base: Backup, segments: list<PitrSegment>, latest: bool, to: CarbonImmutable}
     */
    public function plan(DatabaseInstance $instance, ?CarbonImmutable $target): ?array
    {
        $segments = $this->segments($instance);
        $gaps = PitrGap::query()->where('database_instance_id', $instance->id)->orderBy('detected_at')->get();
        $candidates = [];

        foreach ($this->bases($instance) as $base) {
            $chain = $this->chain($base, $segments, $gaps)['segments'];

            if (($range = self::range($base, $chain)) !== null) {
                $candidates[] = [$base, $chain, $range];
            }
        }

        if ($candidates === []) {
            return null;
        }

        // The newest point there is, or a target at (or after) the end of the newest range: everything shipped.
        $newest = collect($candidates)->sortBy(fn (array $c) => $c[2]['to']->getTimestamp())->last();

        if ($target === null || $target >= $newest[2]['to']) {
            return ['base' => $newest[0], 'segments' => $newest[1], 'latest' => true, 'to' => $newest[2]['to']];
        }

        foreach (array_reverse($candidates) as [$base, $chain, $range]) {
            if ($target < $range['from'] || $target > $range['to']) {
                continue;
            }

            $chosen = [];

            foreach ($chain as $segment) {
                $chosen[] = $segment;

                if (! str_ends_with($segment->name, '.history') && CarbonImmutable::instance($segment->end_time) >= $target) {
                    break;
                }
            }

            return ['base' => $base, 'segments' => $chosen, 'latest' => false, 'to' => $target];
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
     * Shipped segments of the instance's log kind.
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
     * A base's chain, in replay order: from the segment it starts in (log_start), each the next of the one before, in its
     * epoch, spooled after it started, until a hole or the first reported gap after its start (postgres: plus every
     * timeline history file, which restore_command asks for by name).
     *
     * @param  Collection<int, PitrSegment>  $segments
     * @param  Collection<int, PitrGap>  $gaps
     * @return array{segments: list<PitrSegment>, hole: ?array{at: string, detail: string, resolved: bool}}
     */
    private function chain(Backup $base, Collection $segments, Collection $gaps): array
    {
        $postgres = $base->engine === Engine::PostgreSql;
        $start = CarbonImmutable::instance($base->base_started_at ?? $base->base_finished_at)->subSeconds(self::SLACK_SECONDS);
        $gap = $gaps->first(fn (PitrGap $gap) => CarbonImmutable::instance($gap->detected_at) > $start);
        $limit = $gap !== null ? CarbonImmutable::instance($gap->detected_at) : null;
        $history = $postgres ? $segments->filter(fn (PitrSegment $s) => str_ends_with($s->name, '.history'))->keyBy('name') : collect();

        // Candidates: this epoch, spooled after the base started, before the first gap; by name the latest one.
        $candidates = $segments
            ->filter(fn (PitrSegment $s) => (int) $s->epoch === (int) $base->pitr_epoch && self::position($postgres, $s->name) !== null
                && CarbonImmutable::instance($s->end_time) >= $start && ($limit === null || CarbonImmutable::instance($s->end_time) <= $limit))
            ->keyBy('name');
        $byPosition = $candidates->sortBy(fn (PitrSegment $s) => self::position($postgres, $s->name))->values();

        $chain = [];
        $previous = null;

        foreach ($byPosition as $segment) {
            if ($previous === null) {
                if ($base->log_start !== null && $segment->name !== $base->log_start) {
                    // Older ones (spooled just before the base) are skipped; the chain starts at log_start.
                    if (strcmp((string) self::position($postgres, $segment->name), (string) self::position($postgres, $base->log_start)) < 0) {
                        continue;
                    }

                    return ['segments' => [], 'hole' => self::hole($segment, "{$base->log_start} (where a base starts) never arrived")];
                }
            } elseif (! self::follows($postgres, $previous->name, $segment->name, $history)) {
                return ['segments' => self::withHistory($chain, $history), 'hole' => self::hole($previous, "The segment after {$previous->name} never arrived: recovery stops before {$segment->name}")];
            }

            $chain[] = $segment;
            $previous = $segment;
        }

        return ['segments' => self::withHistory($chain, $history), 'hole' => null];
    }

    /**
     * @param  list<PitrSegment>  $chain
     * @param  Collection<string, PitrSegment>  $history
     * @return list<PitrSegment>
     */
    private static function withHistory(array $chain, Collection $history): array
    {
        return $chain === [] ? [] : [...$history->values()->all(), ...$chain];
    }

    /**
     * @return array{at: string, detail: string, resolved: bool}
     */
    private static function hole(PitrSegment $before, string $detail): array
    {
        return ['at' => self::iso(CarbonImmutable::instance($before->end_time)), 'detail' => $detail, 'resolved' => false];
    }

    /**
     * A sortable position of a segment in its log ("tli:log:seg" in hex, or the binlog number), null for files that
     * are not a step of the chain (history, backup and partial files).
     */
    private static function position(bool $postgres, string $name): ?string
    {
        if ($postgres) {
            return preg_match('/^[0-9A-F]{24}$/', $name) === 1 ? substr($name, 0, 8).':'.substr($name, 8, 8).':'.substr($name, 16, 8) : null;
        }

        return preg_match('/^(.+)\.(\d{6,})$/', $name, $m) === 1 ? $m[1].':'.str_pad($m[2], 12, '0', STR_PAD_LEFT) : null;
    }

    /**
     * Whether $next directly follows $previous.
     *
     * @param  Collection<string, PitrSegment>  $history
     */
    private static function follows(bool $postgres, string $previous, string $next, Collection $history): bool
    {
        if (! $postgres) {
            [$base, $n] = [substr($previous, 0, (int) strrpos($previous, '.')), (int) substr($previous, (int) strrpos($previous, '.') + 1)];

            return $next === sprintf('%s.%06d', $base, $n + 1);
        }

        [$tli, $log, $seg] = array_map('hexdec', str_split($previous, 8));
        [$nTli, $nLog, $nSeg] = array_map('hexdec', str_split($next, 8));
        $following = $seg >= self::LAST_SEG ? [$log + 1, 0] : [$log, $seg + 1];

        if ($nTli === $tli) {
            return [$nLog, $nSeg] === $following;
        }

        // A newer timeline (a promotion) goes on from the same or the next segment, once its history arrived.
        return $nTli > $tli && $history->has(sprintf('%08X.history', $nTli)) && ([$nLog, $nSeg] === [$log, $seg] || [$nLog, $nSeg] === $following);
    }

    /**
     * @param  list<PitrSegment>  $chain
     * @return ?array{from: CarbonImmutable, to: CarbonImmutable, base_id: string}
     */
    private static function range(Backup $base, array $chain): ?array
    {
        $from = CarbonImmutable::instance($base->base_finished_at);
        $to = null;

        foreach ($chain as $segment) {
            if (str_ends_with($segment->name, '.history')) {
                continue;
            }

            $end = CarbonImmutable::instance($segment->end_time);
            $to = $to === null || $end > $to ? $end : $to;
        }

        return $to !== null && $to > $from ? ['from' => $from, 'to' => $to, 'base_id' => $base->id] : null;
    }

    private static function iso(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
