<?php

namespace Kiln\Apm\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Kiln\Apm\Recorder;
use Kiln\Apm\Span;

final class NotificationWatcher
{
    /** @var array<string, int> */
    private array $starts = [];

    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(NotificationSending::class, function (NotificationSending $event) {
            if ($this->recorder->recording('notification') && count($this->starts) < 100) {
                $this->starts[$this->key($event)] = $this->recorder->now();
            }
        });

        $events->listen(NotificationSent::class, fn (NotificationSent $e) => $this->record($e, 'sent'));
        $events->listen(NotificationFailed::class, fn (NotificationFailed $e) => $this->record($e, 'failed'));
    }

    private function record(object $event, string $status): void
    {
        if (! $this->recorder->recording('notification')) {
            return;
        }

        $key = $this->key($event);
        $end = $this->recorder->now();
        $start = $this->starts[$key] ?? $end;
        unset($this->starts[$key]);

        $class = get_class($event->notification);
        $channel = is_string($event->channel) ? $event->channel : get_debug_type($event->channel);

        $this->recorder->record('notification', $class, Span::KIND_INTERNAL, $start, $end, [
            'kiln.notification.class' => $class,
            'kiln.notification.channel' => $channel,
            'kiln.notification.status' => $status,
        ], $status === 'failed' ? Span::STATUS_ERROR : Span::STATUS_UNSET);
    }

    private function key(object $event): string
    {
        return spl_object_id($event->notification).':'.(is_string($event->channel) ? $event->channel : '');
    }
}
