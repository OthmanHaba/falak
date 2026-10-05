<?php

namespace Falak\Fleet\Contracts\Data;

use DateTimeImmutable;
use Falak\Fleet\Contracts\CommandStatus;

final readonly class CommandResult
{
    /**
     * @param  array<string, mixed>|null  $result  structured result from the finished event
     */
    public function __construct(
        public string $id,
        public string $serverId,
        public string $type,
        public CommandStatus $status,
        public ?int $exitCode = null,
        public ?array $result = null,
        public ?string $error = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $finishedAt = null,
    ) {}

    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }
}
