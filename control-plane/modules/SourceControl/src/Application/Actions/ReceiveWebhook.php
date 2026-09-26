<?php

namespace Kiln\SourceControl\Application\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Kiln\SourceControl\Domain\Models\Push;
use Kiln\SourceControl\Domain\Models\Webhook;
use Kiln\SourceControl\Events\PushReceived;
use Kiln\SourceControl\Infrastructure\Webhooks\WebhookPayloads;

/**
 * Record the branch pushes of a verified delivery and announce them.
 */
final class ReceiveWebhook
{
    public function __construct(private readonly WebhookPayloads $payloads) {}

    /**
     * @return list<Push>
     */
    public function __invoke(Webhook $webhook, Request $request): array
    {
        $connection = $webhook->connection;
        $webhook->forceFill(['last_delivery_at' => now()])->save();

        $recorded = [];

        foreach ($this->payloads->pushes($connection->provider, $request) as $push) {
            if ($push->commit->sha === '') {
                continue;
            }

            $recorded[] = Push::query()->create([
                'organization_id' => $webhook->organization_id,
                'connection_id' => $connection->id,
                'webhook_id' => $webhook->id,
                'repository' => $webhook->repository,
                'branch' => Str::limit($push->branch, 250, ''),
                'sha' => Str::limit($push->commit->sha, 64, ''),
                'before_sha' => $push->beforeSha ? Str::limit($push->beforeSha, 64, '') : null,
                'author_name' => $push->commit->authorName ? Str::limit($push->commit->authorName, 250, '') : null,
                'author_email' => $push->commit->authorEmail ? Str::limit($push->commit->authorEmail, 250, '') : null,
                'message' => Str::limit($push->commit->message, 10000),
                'url' => $push->commit->url ? Str::limit($push->commit->url, 1000, '') : null,
                'pusher' => $push->pusher ? Str::limit($push->pusher, 250, '') : null,
                'committed_at' => $push->commit->committedAt,
                'received_at' => now(),
            ]);

            PushReceived::dispatch(
                $webhook->organization_id,
                $connection->id,
                $connection->provider->value,
                $webhook->repository,
                $push->branch,
                $push->commit,
                $push->pusher,
                $push->beforeSha,
            );
        }

        return $recorded;
    }
}
