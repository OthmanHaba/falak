<?php

namespace Kiln\Apm\Watchers;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Contracts\Events\Dispatcher;
use Kiln\Apm\Recorder;
use Kiln\Apm\Span;

final class CacheWatcher
{
    /** @var array<string, int> "store\0key" => start ns (from the *ing events, when available) */
    private array $starts = [];

    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Dispatcher $events): void
    {
        $start = function (object $event) {
            if ($this->recorder->recording('cache') && count($this->starts) < 1000) {
                $this->starts[($event->storeName ?? '')."\0".$event->key] = $this->recorder->now();
            }
        };

        $events->listen([RetrievingKey::class, WritingKey::class, ForgettingKey::class], $start);
        $events->listen(CacheHit::class, fn (CacheHit $e) => $this->record('hit', $e));
        $events->listen(CacheMissed::class, fn (CacheMissed $e) => $this->record('miss', $e));
        $events->listen(KeyWritten::class, fn (KeyWritten $e) => $this->record('write', $e));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $e) => $this->record('forget', $e));
    }

    private function record(string $op, object $event): void
    {
        if (! $this->recorder->recording('cache')) {
            return;
        }

        $store = (string) ($event->storeName ?? '');
        $id = $store."\0".$event->key;
        $end = $this->recorder->now();
        $start = $this->starts[$id] ?? $end;
        unset($this->starts[$id]);

        $this->recorder->record('cache', 'cache '.$op, Span::KIND_INTERNAL, $start, $end, [
            'kiln.cache.op' => $op,
            'kiln.cache.key' => (string) $event->key,
            'kiln.cache.store' => $store,
        ]);
    }
}
