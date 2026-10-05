<?php

namespace Falak\Apm;

use Throwable;

/**
 * A recorded span. Plain data holder: nothing is formatted until flush.
 *
 * @internal
 */
final class Span
{
    public const KIND_INTERNAL = 1;
    public const KIND_SERVER = 2;
    public const KIND_CLIENT = 3;
    public const KIND_PRODUCER = 4;
    public const KIND_CONSUMER = 5;

    public const STATUS_UNSET = 0;
    public const STATUS_OK = 1;
    public const STATUS_ERROR = 2;

    public int $endNs = 0;

    public int $status = self::STATUS_UNSET;

    public ?string $statusMessage = null;

    /** @var list<array{name: string, timeNs: int, attributes: array<string, mixed>}> */
    public array $events = [];

    /** @var list<array{traceId: string, spanId: string, attributes?: array<string, mixed>}> */
    public array $links = [];

    /** @var array<int, array{0: Throwable, 1: int, 2: bool}> exception object id => [throwable, timeNs, handled] */
    public array $exceptions = [];

    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public string $name,
        public int $kind,
        public int $startNs,
        public array $attributes = [],
    ) {
    }

    public function eventType(): ?string
    {
        return $this->attributes['falak.event.type'] ?? null;
    }

    public function recordException(Throwable $e, int $timeNs, bool $handled): void
    {
        $id = spl_object_id($e);

        if (isset($this->exceptions[$id])) {
            // An exception first reported as handled may later turn out to be unhandled.
            $this->exceptions[$id][2] = $this->exceptions[$id][2] && $handled;
        } else {
            $this->exceptions[$id] = [$e, $timeNs, $handled];
        }

        if (! $handled) {
            $this->status = self::STATUS_ERROR;
            $this->statusMessage = $e->getMessage();
        }
    }
}
