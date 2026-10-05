<?php

namespace Falak\Apm;

/**
 * State of one in-flight trace (a request, job, command or scheduled task).
 *
 * @internal
 */
final class TraceContext
{
    /** @var list<Span> active (open) spans, root first */
    public array $stack = [];

    /** @var list<Span> finished spans */
    public array $spans = [];

    public int $dropped = 0;

    /** @var array<string, int> request timeline marks (ns) */
    public array $marks = [];

    public function __construct(
        public readonly string $traceId,
        public readonly bool $sampled,
        public readonly Span $root,
    ) {
        $this->stack[] = $root;
    }
}
