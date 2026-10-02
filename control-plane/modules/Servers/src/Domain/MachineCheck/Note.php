<?php

namespace Kiln\Servers\Domain\MachineCheck;

/**
 * One finding about a component: a conflict (block), something to watch (warning) or context (info).
 */
final readonly class Note
{
    public function __construct(
        public Severity $severity,
        public string $message,
        public ?string $hint = null,
    ) {}

    public static function block(string $message, ?string $hint = null): self
    {
        return new self(Severity::Block, $message, $hint);
    }

    public static function warning(string $message, ?string $hint = null): self
    {
        return new self(Severity::Warning, $message, $hint);
    }

    public static function info(string $message, ?string $hint = null): self
    {
        return new self(Severity::Info, $message, $hint);
    }

    /**
     * @return array{severity: string, message: string, hint: ?string}
     */
    public function toArray(): array
    {
        return ['severity' => $this->severity->value, 'message' => $this->message, 'hint' => $this->hint];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Severity::tryFrom((string) ($data['severity'] ?? '')) ?? Severity::Info, (string) ($data['message'] ?? ''), isset($data['hint']) ? (string) $data['hint'] : null);
    }
}
