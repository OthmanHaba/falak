<?php

namespace Kiln\Insights\Domain\Support;

final readonly class StackFrame
{
    public function __construct(
        public string $raw,
        public ?string $file,
        public ?int $line,
        public ?string $function,
        public bool $inApp,
    ) {}

    /** Stable identity used for fingerprinting: normalized file + function, never line numbers. */
    public function identity(): string
    {
        return ($this->file ?? '?').':'.($this->function ?? '?');
    }

    /**
     * @return array{raw: string, file: ?string, line: ?int, function: ?string, in_app: bool}
     */
    public function toArray(): array
    {
        return ['raw' => $this->raw, 'file' => $this->file, 'line' => $this->line, 'function' => $this->function, 'in_app' => $this->inApp];
    }
}
