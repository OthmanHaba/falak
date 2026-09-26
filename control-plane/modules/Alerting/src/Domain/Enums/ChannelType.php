<?php

namespace Kiln\Alerting\Domain\Enums;

enum ChannelType: string
{
    case Email = 'email';
    case Slack = 'slack';
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Slack => 'Slack',
            self::Discord => 'Discord',
            self::Telegram => 'Telegram',
            self::Webhook => 'Webhook',
        };
    }
}
