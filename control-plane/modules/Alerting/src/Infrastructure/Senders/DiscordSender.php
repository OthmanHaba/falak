<?php

namespace Kiln\Alerting\Infrastructure\Senders;

use Kiln\Alerting\Application\AlertMessage;
use Kiln\Alerting\Domain\Enums\ChannelType;

/** Discord webhook. Config: {webhook_url}. Discord answers 204 No Content. */
final class DiscordSender extends HttpSender
{
    public function type(): ChannelType
    {
        return ChannelType::Discord;
    }

    public function rules(): array
    {
        return ['config.webhook_url' => ['required', 'string', 'max:500', 'url:https', 'regex:#^https://(discord\.com|discordapp\.com|canary\.discord\.com|ptb\.discord\.com)/api/webhooks/#']];
    }

    public function secretKeys(): array
    {
        return ['webhook_url'];
    }

    public function send(array $config, AlertMessage $message): void
    {
        $fields = [
            ['name' => 'Severity', 'value' => $message->resolved ? 'Resolved' : $message->severity->label(), 'inline' => true],
            ['name' => 'Type', 'value' => $message->type, 'inline' => true],
        ];

        foreach ($message->context as $key => $value) {
            if ($value !== null && $value !== '' && count($fields) < 12) {
                $fields[] = ['name' => mb_substr((string) $key, 0, 256), 'value' => mb_substr(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value, 0, 1024), 'inline' => true];
            }
        }

        $embed = array_filter([
            'title' => mb_substr($message->headline(), 0, 256),
            'description' => $message->body !== '' ? mb_substr($message->body, 0, 4096) : null,
            'url' => $message->url,
            'color' => self::color($message),
            'fields' => $fields,
            'timestamp' => $message->createdAt->format(DATE_ATOM),
        ], fn ($value) => $value !== null);

        $this->post((string) $config['webhook_url'], ['username' => 'Kiln', 'allowed_mentions' => ['parse' => []], 'embeds' => [$embed]]);
    }
}
