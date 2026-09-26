<?php

namespace Kiln\Insights\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Fleet\Events\InsightsReceived;
use Kiln\Insights\Application\Actions\IngestInsights;

final class IngestReceivedInsights implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(private readonly IngestInsights $ingest) {}

    public function handle(InsightsReceived $event): void
    {
        ($this->ingest)($event->organizationId, $event->serverId, $event->agentId, $event->items);
    }
}
