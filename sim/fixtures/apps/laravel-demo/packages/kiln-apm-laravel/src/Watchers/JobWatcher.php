<?php

namespace Kiln\Apm\Watchers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Queue;
use Kiln\Apm\Recorder;
use Kiln\Apm\Span;
use Throwable;

/**
 * Each job on an async connection becomes its own trace, linked to the trace that dispatched
 * it through `kiln.traceparent` in the job payload. Sync jobs are child spans of the caller.
 */
final class JobWatcher
{
    /** @var list<array{job: object, span: ?Span, root: bool}> */
    private array $stack = [];

    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Dispatcher $events): void
    {
        // The hook is static on Queue: resolve the current recorder lazily so test suites /
        // Octane sandboxes never hold a stale instance.
        Queue::createPayloadUsing(static function () {
            try {
                $app = Container::getInstance();

                if (! $app->bound(Recorder::class)) {
                    return [];
                }

                $traceparent = $app->make(Recorder::class)->traceparent();

                return $traceparent !== null ? ['kiln' => ['traceparent' => $traceparent]] : [];
            } catch (Throwable) {
                return [];
            }
        });

        $events->listen(JobProcessing::class, fn (JobProcessing $e) => $this->start($e));
        $events->listen(JobProcessed::class, fn (JobProcessed $e) => $this->finish($e->job, 'processed'));
        $events->listen(JobReleasedAfterException::class, fn (JobReleasedAfterException $e) => $this->finish($e->job, 'released'));
        $events->listen(JobFailed::class, fn (JobFailed $e) => $this->finish($e->job, 'failed', $e->exception));
        $events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $e) {
            if (($entry = $this->find($e->job)) !== null && $entry['span'] !== null) {
                $this->recorder->recordException($e->exception, false, $entry['span']);
            }
        });
    }

    private function start(JobProcessing $event): void
    {
        try {
            $job = $event->job;
            $class = $job->resolveName();

            if (! $this->recorder->typeEnabled('job') || $this->recorder->ignored('jobs', $class)) {
                return;
            }

            $attributes = [
                'messaging.system' => 'laravel',
                'messaging.destination.name' => (string) $job->getQueue(),
                'messaging.message.id' => (string) $job->getJobId(),
                'kiln.job.class' => $class,
                'kiln.job.attempt' => (int) $job->attempts(),
                'kiln.queue.connection' => (string) $event->connectionName,
            ];

            if ($event->connectionName === 'sync' && $this->recorder->active()) {
                $this->stack[] = ['job' => $job, 'span' => $this->recorder->startSpan('job', $class, Span::KIND_CONSUMER, $attributes), 'root' => false];

                return;
            }

            // Worker boundary: ship anything buffered between jobs (e.g. worker logs).
            $this->recorder->flush();

            $links = [];
            $payload = $job->payload();
            $parent = is_string($payload['kiln']['traceparent'] ?? null) ? Recorder::parseTraceparent($payload['kiln']['traceparent']) : null;

            if ($parent !== null) {
                $links[] = ['traceId' => $parent['traceId'], 'spanId' => $parent['spanId'], 'attributes' => ['kiln.link.type' => 'dispatched_by']];
                $attributes['kiln.job.dispatch_trace_id'] = $parent['traceId'];
            }

            $span = $this->recorder->beginTrace('job', $class, Span::KIND_CONSUMER, $attributes, null, null, $links);
            $this->stack[] = ['job' => $job, 'span' => $span, 'root' => true];
        } catch (Throwable) {
        }
    }

    private function finish(object $job, string $status, ?Throwable $exception = null): void
    {
        try {
            $index = $this->indexOf($job);

            if ($index === null) {
                return;
            }

            ['span' => $span, 'root' => $root] = $this->stack[$index];
            array_splice($this->stack, $index, 1);

            if ($span !== null) {
                $span->attributes['kiln.job.status'] = $status;

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

    /** @return array{job: object, span: ?Span, root: bool}|null */
    private function find(object $job): ?array
    {
        $index = $this->indexOf($job);

        return $index === null ? null : $this->stack[$index];
    }

    private function indexOf(object $job): ?int
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]['job'] === $job) {
                return $i;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->stack = [];
    }
}
