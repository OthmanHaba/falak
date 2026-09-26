<?php

namespace Kiln\Fleet\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Insights tee from an agent (contracts/telemetry README): exception + aggregate summaries.
 * Fleet only authenticates and parses; the Insights module owns grouping and thresholds.
 */
final class InsightsReceived
{
    use Dispatchable;

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public string $agentId,
        public string $organizationId,
        public ?string $serverId,
        public array $items,
    ) {}
}
