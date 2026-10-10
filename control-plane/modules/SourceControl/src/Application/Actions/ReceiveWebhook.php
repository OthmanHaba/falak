<?php

namespace Falak\SourceControl\Application\Actions;

use Falak\SourceControl\Domain\Models\Push;
use Falak\SourceControl\Domain\Models\Webhook;
use Falak\SourceControl\Infrastructure\Webhooks\WebhookPayloads;
use Illuminate\Http\Request;

/**
 * Record the branch pushes of a verified per-repository delivery and announce them, or announce its pull request
 * event (opened, updated, closed, commented).
 */
final class ReceiveWebhook
{
    /** Pull request events announced by the last call. */
    public int $pullRequestEvents = 0;

    public function __construct(
        private readonly WebhookPayloads $payloads,
        private readonly RecordPushes $record,
        private readonly AnnouncePullRequest $announce,
    ) {}

    /**
     * @return list<Push>
     */
    public function __invoke(Webhook $webhook, Request $request): array
    {
        $connection = $webhook->connection;
        $webhook->forceFill(['last_delivery_at' => now()])->save();
        $this->pullRequestEvents = (int) ($this->announce)($connection, $webhook->repository, $this->payloads->pullRequestEvent($connection->provider, $request));

        return ($this->record)($connection, $webhook->repository, $this->payloads->pushes($connection->provider, $request), $webhook);
    }
}
