<?php

namespace Kiln\Fleet\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A command failed, timed out, was cancelled or could not be delivered.
 */
final class CommandFailed
{
    use Dispatchable;

    /**
     * @param  string  $status  CommandStatus value: failed | timed_out | cancelled
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(
        public string $commandId,
        public string $organizationId,
        public string $serverId,
        public string $type,
        public string $idempotencyKey,
        public string $status,
        public ?string $error,
        public ?int $exitCode,
        public ?array $result = null,
    ) {}
}
