<?php

namespace Kiln\Alerting\Infrastructure\Senders;

use Kiln\Alerting\Domain\Enums\ChannelType;

final class SenderRegistry
{
    /** @var array<string, ChannelSender> */
    private array $senders = [];

    /**
     * @param  iterable<ChannelSender>  $senders
     */
    public function __construct(iterable $senders)
    {
        foreach ($senders as $sender) {
            $this->senders[$sender->type()->value] = $sender;
        }
    }

    public function for(ChannelType $type): ChannelSender
    {
        return $this->senders[$type->value] ?? throw new \InvalidArgumentException("No sender for channel type [{$type->value}].");
    }
}
