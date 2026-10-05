<?php

namespace Falak\Apm;

use Closure;
use Illuminate\Support\Str;
use Falak\Apm\Otlp\Encoder;
use Falak\Apm\Transport\Transport;
use Throwable;

/**
 * In-memory telemetry buffer.
 *
 * Hot-path methods only append small structs; redaction, N+1 analysis and encoding
 * happen in flush(), which runs after the response has been sent.
 */
class Recorder
{
    private bool $enabled;

    /** @var array<string, bool> */
    private array $events;

    /** @var array<string, float> */
    private array $rates;

    private int $maxSpans;

    private int $maxLogs;

    private int $minLogLevel;

    /** @var array{paths: list<string>, commands: list<string>, jobs: list<string>} */
    private array $ignore;

    private bool $timeline;

    private ?TraceContext $ctx = null;

    /** @var list<TraceContext> contexts suspended by a nested trace (e.g. a queued job inside a command) */
    private array $suspended = [];

    /** @var list<Span> finished spans of finished (sampled) traces */
    private array $outbox = [];

    /** @var list<Span> spans recorded before a request trace was started (service provider boot) */
    private array $orphans = [];

    /** @var list<array{timeNs: int, level: string, message: string, context: array<string, mixed>, traceId: ?string, spanId: ?string}> */
    private array $logs = [];

    private int $wall0;

    private int $hr0;

    private const LEVELS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];

    /** @param array<string, mixed> $config */
    public function __construct(
        array $config,
        private Transport $transport,
        private Encoder $encoder,
        private Redactor $redactor,
        private bool $collectOrphans = false,
    ) {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->events = array_map('boolval', $config['events'] ?? []);
        $this->rates = array_map('floatval', $config['sample_rates'] ?? []);
        $this->maxSpans = max(1, (int) ($config['max_spans_per_trace'] ?? 1000));
        $this->maxLogs = max(0, (int) ($config['max_logs'] ?? 1000));
        $this->minLogLevel = self::LEVELS[strtolower((string) ($config['log_level'] ?? 'debug'))] ?? 100;
        $this->timeline = (bool) ($config['timeline'] ?? true);
        $this->ignore = [
            'paths' => array_values($config['ignore']['paths'] ?? []),
            'commands' => array_values($config['ignore']['commands'] ?? []),
            'jobs' => array_values($config['ignore']['jobs'] ?? []),
        ];
        $this->wall0 = (int) (microtime(true) * 1e9);
        $this->hr0 = hrtime(true);
    }

    // ---------------------------------------------------------------- state

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function typeEnabled(string $type): bool
    {
        return $this->enabled && ($this->events[$type] ?? true);
    }

    public function timelineEnabled(): bool
    {
        return $this->timeline;
    }

    /** Should a child span / event of this type be recorded right now? */
    public function recording(string $type): bool
    {
        return $this->enabled
            && ($this->events[$type] ?? true)
            && ($this->ctx !== null ? $this->ctx->sampled : $this->collectOrphans);
    }

    public function context(): ?TraceContext
    {
        return $this->ctx;
    }

    public function active(): bool
    {
        return $this->ctx !== null;
    }

    public function currentSpan(): ?Span
    {
        if ($this->ctx === null) {
            return null;
        }

        return $this->ctx->stack[count($this->ctx->stack) - 1] ?? $this->ctx->root;
    }

    public function traceparent(): ?string
    {
        if ($this->ctx === null || ! $this->ctx->sampled) {
            return null;
        }

        return '00-'.$this->ctx->traceId.'-'.$this->currentSpan()?->spanId.'-01';
    }

    public function now(): int
    {
        return $this->wall0 + (hrtime(true) - $this->hr0);
    }

    public function ignored(string $kind, string $value): bool
    {
        foreach ($this->ignore[$kind] ?? [] as $pattern) {
            if (Str::is($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    public function redactUsing(Closure $callback): void
    {
        $this->redactor->using($callback);
    }

    // ---------------------------------------------------------------- traces

    /**
     * Start a new trace (request, job, command, scheduled task). An already active trace is
     * suspended and restored by endTrace().
     *
     * @param array<string, mixed> $attributes
     * @param list<array{traceId: string, spanId: string, attributes?: array<string, mixed>}> $links
     */
    public function beginTrace(
        string $type,
        string $name,
        int $kind,
        array $attributes = [],
        ?string $traceparent = null,
        ?int $startNs = null,
        array $links = [],
    ): Span {
        if ($this->ctx !== null) {
            $this->suspended[] = $this->ctx;
        }

        $parent = $traceparent !== null ? self::parseTraceparent($traceparent) : null;
        $traceId = $parent['traceId'] ?? self::traceId();
        $rate = $this->rates[$type] ?? 1.0;
        $sampled = $this->typeEnabled($type) && ($rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate));

        $root = new Span($traceId, self::spanId(), $parent['spanId'] ?? null, $name, $kind, $startNs ?? $this->now(), ['falak.event.type' => $type] + $attributes);
        $root->links = $links;

        $this->ctx = new TraceContext($traceId, $sampled, $root);

        if ($this->orphans !== []) {
            if ($sampled && $type === 'request') {
                foreach ($this->orphans as $orphan) {
                    $orphan->traceId = $traceId;
                    $orphan->parentSpanId ??= $root->spanId;
                    $this->ctx->spans[] = $orphan;
                }
            }

            $this->orphans = [];
        }

        return $root;
    }

    /** End the active trace, queue its spans for flushing and restore any suspended trace. */
    public function endTrace(?int $endNs = null): ?Span
    {
        $ctx = $this->ctx;

        if ($ctx === null) {
            return null;
        }

        $endNs ??= $this->now();

        // Close spans that were left open (e.g. an exception skipped their end event).
        while (count($ctx->stack) > 1) {
            $span = array_pop($ctx->stack);
            $span->endNs = $endNs;
            $this->addFinished($ctx, $span);
        }

        $root = $ctx->root;
        $root->endNs = $endNs;

        if ($ctx->dropped > 0) {
            $root->attributes['falak.trace.dropped_spans'] = $ctx->dropped;
        }

        if ($ctx->sampled) {
            $ctx->spans[] = $root;
            array_push($this->outbox, ...$ctx->spans);

            // Bound memory if nothing ever flushes (misconfigured long-running process).
            if (count($this->outbox) > $this->maxSpans * 10) {
                $this->outbox = array_slice($this->outbox, -$this->maxSpans * 10);
            }
        }

        $this->ctx = array_pop($this->suspended);

        return $root;
    }

    // ---------------------------------------------------------------- spans

    /**
     * Open a child span of the current span (for work that contains other work).
     *
     * @param array<string, mixed> $attributes
     */
    public function startSpan(string $type, string $name, int $kind, array $attributes = []): ?Span
    {
        $ctx = $this->ctx;

        if ($ctx === null || ! $this->recording($type) || ! $this->sampleChild($type)) {
            return null;
        }

        $parent = $ctx->stack[count($ctx->stack) - 1];
        $span = new Span($ctx->traceId, self::spanId(), $parent->spanId, $name, $kind, $this->now(), ['falak.event.type' => $type] + $attributes);
        $ctx->stack[] = $span;

        return $span;
    }

    public function endSpan(?Span $span, ?int $endNs = null): void
    {
        $ctx = $this->ctx;

        if ($span === null || $ctx === null) {
            return;
        }

        for ($i = count($ctx->stack) - 1; $i > 0; $i--) {
            if ($ctx->stack[$i] === $span) {
                array_splice($ctx->stack, $i, 1);
                $span->endNs = $endNs ?? $this->now();
                $this->addFinished($ctx, $span);

                return;
            }
        }
    }

    /**
     * Record an already completed child span.
     *
     * @param array<string, mixed> $attributes
     */
    public function record(
        string $type,
        string $name,
        int $kind,
        int $startNs,
        int $endNs,
        array $attributes,
        int $status = Span::STATUS_UNSET,
        ?string $spanId = null,
        ?string $statusMessage = null,
    ): ?Span {
        if (! $this->recording($type) || ! $this->sampleChild($type)) {
            return null;
        }

        $ctx = $this->ctx;
        $parent = $ctx !== null ? $ctx->stack[count($ctx->stack) - 1] : null;

        $span = new Span($ctx->traceId ?? '', $spanId ?? self::spanId(), $parent?->spanId, $name, $kind, $startNs, ['falak.event.type' => $type] + $attributes);
        $span->endNs = $endNs;
        $span->status = $status;
        $span->statusMessage = $statusMessage;

        if ($ctx !== null) {
            $this->addFinished($ctx, $span);
        } elseif (count($this->orphans) < 200) {
            $this->orphans[] = $span;
        }

        return $span;
    }

    /** Record a plain (untyped) child span, e.g. request timeline phases. */
    public function recordPhase(string $name, int $startNs, int $endNs, array $attributes = []): void
    {
        $ctx = $this->ctx;

        if ($ctx === null || ! $ctx->sampled || $endNs <= $startNs) {
            return;
        }

        $span = new Span($ctx->traceId, self::spanId(), $ctx->root->spanId, $name, Span::KIND_INTERNAL, $startNs, $attributes);
        $span->endNs = $endNs;
        $this->addFinished($ctx, $span);
    }

    private function addFinished(TraceContext $ctx, Span $span): void
    {
        if (count($ctx->spans) >= $this->maxSpans) {
            $ctx->dropped++;

            return;
        }

        $ctx->spans[] = $span;
    }

    private function sampleChild(string $type): bool
    {
        $rate = $this->rates[$type] ?? 1.0;

        return $rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate);
    }

    // ---------------------------------------------------------------- exceptions & logs

    public function recordException(Throwable $e, bool $handled = true, ?Span $span = null): void
    {
        if (! $this->enabled || ! ($this->events['exceptions'] ?? true)) {
            return;
        }

        $span ??= $this->currentSpan();

        if ($span === null || ($this->ctx !== null && ! $this->ctx->sampled)) {
            return;
        }

        $span->recordException($e, $this->now(), $handled);
    }

    /** @param array<string, mixed> $context */
    public function recordLog(string $level, string $message, array $context = []): void
    {
        if (! $this->enabled || ! ($this->events['logs'] ?? true) || count($this->logs) >= $this->maxLogs) {
            return;
        }

        $level = strtolower($level);

        if ((self::LEVELS[$level] ?? 100) < $this->minLogLevel) {
            return;
        }

        $rate = $this->rates['logs'] ?? 1.0;

        if ($rate < 1.0 && ($rate <= 0.0 || mt_rand() / mt_getrandmax() >= $rate)) {
            return;
        }

        $span = $this->ctx !== null && $this->ctx->sampled ? $this->currentSpan() : null;

        $this->logs[] = [
            'timeNs' => $this->now(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'traceId' => $span?->traceId,
            'spanId' => $span?->spanId,
        ];
    }

    // ---------------------------------------------------------------- flushing

    /** @return array{spans: list<Span>, logs: int} */
    public function pending(): array
    {
        return ['spans' => $this->outbox, 'logs' => count($this->logs)];
    }

    /**
     * Send everything buffered. Never throws.
     */
    public function flush(): void
    {
        if ($this->outbox === [] && $this->logs === []) {
            return;
        }

        $spans = $this->outbox;
        $logs = $this->logs;
        $this->outbox = [];
        $this->logs = [];

        try {
            if ($spans !== []) {
                $this->prepare($spans);
                $this->transport->send('/v1/traces', $this->encoder->traces($spans));
            }

            if ($logs !== []) {
                foreach ($logs as $i => $log) {
                    $logs[$i]['context'] = $this->logContext($log['context']);
                }

                $this->transport->send('/v1/logs', $this->encoder->logs($logs));
            }
        } catch (Throwable) {
            // Telemetry must never break the application.
        }
    }

    /** Drop all state (Octane: between requests). */
    public function reset(): void
    {
        $this->ctx = null;
        $this->suspended = [];
        $this->outbox = [];
        $this->orphans = [];
        $this->logs = [];
    }

    /** @param list<Span> $spans */
    private function prepare(array $spans): void
    {
        $counts = [];

        foreach ($spans as $span) {
            $this->redactor->span($span);

            if (($span->attributes['falak.event.type'] ?? null) === 'query') {
                $key = $span->traceId."\0".($span->attributes['falak.query.connection'] ?? '')."\0".($span->attributes['db.query.text'] ?? '');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return;
        }

        foreach ($spans as $span) {
            if (($span->attributes['falak.event.type'] ?? null) === 'query') {
                $key = $span->traceId."\0".($span->attributes['falak.query.connection'] ?? '')."\0".($span->attributes['db.query.text'] ?? '');
                $span->attributes['falak.query.repeat_count'] = $counts[$key];
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function logContext(array $context): array
    {
        $attributes = [];

        if (($e = $context['exception'] ?? null) instanceof Throwable) {
            unset($context['exception']);
            $attributes = Encoder::exceptionAttributes($e, true);
            unset($attributes['falak.exception.handled'], $attributes['exception.escaped']);
        }

        foreach ($this->redactor->context($context) as $key => $value) {
            $attributes['falak.context.'.$key] = $value;
        }

        return $attributes;
    }

    // ---------------------------------------------------------------- ids

    public static function traceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** @return array{traceId: string, spanId: string, sampled: bool}|null */
    public static function parseTraceparent(string $header): ?array
    {
        if (! preg_match('/^[\da-f]{2}-([\da-f]{32})-([\da-f]{16})-([\da-f]{2})$/', strtolower(trim($header)), $m)) {
            return null;
        }

        if ($m[1] === str_repeat('0', 32) || $m[2] === str_repeat('0', 16)) {
            return null;
        }

        return ['traceId' => $m[1], 'spanId' => $m[2], 'sampled' => (hexdec($m[3]) & 1) === 1];
    }
}
