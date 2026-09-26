<?php

namespace Kiln\Alerting\Application\Actions;

use Kiln\Alerting\Application\AlertMessage;
use Kiln\Alerting\Domain\Models\Channel;
use Kiln\Alerting\Infrastructure\Senders\DeliveryFailed;
use Kiln\Alerting\Infrastructure\Senders\SenderRegistry;

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
