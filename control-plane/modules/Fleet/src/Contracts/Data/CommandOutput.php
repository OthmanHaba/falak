<?php

namespace Kiln\Fleet\Contracts\Data;

final readonly class CommandOutput
{
    /**
     * @param  list<array{seq: int, stream: string, data: string, at: string}>  $lines
     */
    public function __construct(
        public string $commandId,
        public array $lines,
        public int $lastSeq,
    ) {}

    public function text(): string
    {
        return implode('', array_column($this->lines, 'data'));
    }
}
