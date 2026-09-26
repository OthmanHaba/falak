<?php

namespace Kiln\Insights\Domain\Support;

final readonly class Fingerprint
{
    /**
     * @param  list<StackFrame>  $frames  all parsed frames
     */
    public function __construct(
        public string $hash,
        public string $type,
        public ?string $culprit,
        public array $frames,
    ) {}
}
