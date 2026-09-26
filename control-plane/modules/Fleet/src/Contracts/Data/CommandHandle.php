<?php

namespace Kiln\Fleet\Contracts\Data;

final readonly class CommandHandle
{
    public function __construct(
        public string $id,
        public string $serverId,
        public string $agentId,
        public string $type,
        public string $idempotencyKey,
    ) {}
}
