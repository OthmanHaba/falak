<?php

namespace Falak\Apm\Watchers;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Falak\Apm\Recorder;
use Falak\Apm\Span;
use Throwable;

final class ScheduleWatcher
{
    /** @var array<int, array{span: ?Span, root: bool}> task object id => entry */
    private array $running = [];

    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            try {
                if (! $this->recorder->typeEnabled('scheduled_task')) {
                    return;
                }

                [$name, $attributes] = $this->describe($event->task);

                $this->running[spl_object_id($event->task)] = $this->recorder->active()
                    ? ['span' => $this->recorder->startSpan('scheduled_task', $name, Span::KIND_INTERNAL, $attributes), 'root' => false]
                    : ['span' => $this->recorder->beginTrace('scheduled_task', $name, Span::KIND_INTERNAL, $attributes), 'root' => true];
            } catch (Throwable) {
            }
        });

        $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => $this->finish($e->task, 'finished'));
        $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => $this->finish($e->task, 'failed', $e->exception));

        $events->listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            try {
                if (! $this->recorder->typeEnabled('scheduled_task')) {
                    return;
                }

                [$name, $attributes] = $this->describe($event->task);
                $attributes['falak.schedule.status'] = 'skipped';

                if ($this->recorder->active()) {
                    $now = $this->recorder->now();
                    $this->recorder->record('scheduled_task', $name, Span::KIND_INTERNAL, $now, $now, $attributes);
                } else {
                    $this->recorder->beginTrace('scheduled_task', $name, Span::KIND_INTERNAL, $attributes);
                    $this->recorder->endTrace();
                    $this->recorder->flush();
                }
            } catch (Throwable) {
            }
        });
    }

    private function finish(object $task, string $status, ?Throwable $exception = null): void
    {
        try {
            $id = spl_object_id($task);

            if (! isset($this->running[$id])) {
                return;
            }

            ['span' => $span, 'root' => $root] = $this->running[$id];
            unset($this->running[$id]);

            if ($span !== null) {
                $span->attributes['falak.schedule.status'] = $status;

                if ($exception !== null) {
                    $this->recorder->recordException($exception, false, $span);
                }

                if ($status === 'failed') {
                    $span->status = Span::STATUS_ERROR;
                }
            }

            if ($root) {
                $this->recorder->endTrace();
                $this->recorder->flush();
            } else {
                $this->recorder->endSpan($span);
            }
        } catch (Throwable) {
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function describe(object $task): array
    {
        // Prefer the explicit ->name()/->description(), then the raw command, then the summary.
        $name = $task->description ?? null;

        if (($name === null || $name === '') && isset($task->command)) {
            $name = $task->command;
        }

        if (($name === null || $name === '') && method_exists($task, 'getSummaryForDisplay')) {
            $name = $task->getSummaryForDisplay();
        }

        $name = (string) ($name ?: 'Closure');

        // Normalise "'/usr/bin/php' 'artisan' inspire" → "artisan inspire".
        $name = trim((string) preg_replace("/^'[^']*php[^']*'\s+'artisan'/", 'artisan', $name));

        return [$name, [
            'falak.schedule.name' => $name,
            'falak.schedule.expression' => (string) ($task->expression ?? ''),
            'falak.schedule.timezone' => isset($task->timezone) ? (is_string($task->timezone) ? $task->timezone : (string) $task->timezone->getName()) : null,
        ]];
    }

    public function reset(): void
    {
        $this->running = [];
    }
}
