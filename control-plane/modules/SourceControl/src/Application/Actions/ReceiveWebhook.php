<?php

namespace Kiln\SourceControl\Application\Actions;

use Illuminate\Http\Request;
use Kiln\SourceControl\Domain\Models\Push;
use Kiln\SourceControl\Domain\Models\Webhook;
use Kiln\SourceControl\Infrastructure\Webhooks\WebhookPayloads;

/**
 * Record the branch pushes of a verified per-repository delivery and announce them.
 */
final class ReceiveWebhook
{
    public function __construct(
        private readonly WebhookPayloads $payloads,
        private readonly RecordPushes $record,
    ) {}

    /**
     * @return list<Push>
     */
    public function __invoke(Webhook $webhook, Request $request): array
    {
        $connection = $webhook->connection;
        $webhook->forceFill(['last_delivery_at' => now()])->save();

        return ($this->record)($connection, $webhook->repository, $this->payloads->pushes($connection->provider, $request), $webhook);
    }
}
