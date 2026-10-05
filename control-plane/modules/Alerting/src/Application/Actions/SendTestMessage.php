<?php

namespace Falak\Alerting\Application\Actions;

use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Infrastructure\Senders\DeliveryFailed;
use Falak\Alerting\Infrastructure\Senders\SenderRegistry;

/**
 * Sends a test message synchronously. Returns null on success, else the (secret-free) error.
 */
final class SendTestMessage
{
    public function __construct(private readonly SenderRegistry $senders) {}

    public function __invoke(Channel $channel): ?string
    {
        try {
            $this->senders->for($channel->type)->send($channel->config, AlertMessage::test($channel->organization_id, $channel->name));
        } catch (DeliveryFailed $e) {
            $channel->forceFill(['last_error' => $e->getMessage()])->save();

            return $e->getMessage();
        }

        $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();

        return null;
    }
}
