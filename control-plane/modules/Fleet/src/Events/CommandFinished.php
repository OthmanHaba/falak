<?php

namespace Kiln\Fleet\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A command completed successfully (exit code 0, no error).
 */
final class CommandFinished
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(
        public string $commandId,
        public string $organizationId,
        public string $serverId,
        public string $type,
        public string $idempotencyKey,
        public ?int $exitCode,
        public ?array $result,
    ) {}
}
