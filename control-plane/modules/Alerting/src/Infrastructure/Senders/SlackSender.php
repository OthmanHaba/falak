<?php

namespace Falak\Alerting\Infrastructure\Senders;

use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Domain\Enums\ChannelType;

/** Slack incoming webhook. Config: {webhook_url}. */
final class SlackSender extends HttpSender
{
    public function type(): ChannelType
    {
        return ChannelType::Slack;
    }

    public function rules(): array
    {
        return ['config.webhook_url' => ['required', 'string', 'max:500', 'url:https', 'regex:#^https://hooks\.slack\.com/(services|workflows|triggers)/#']];
    }

    public function secretKeys(): array
    {
        return ['webhook_url'];
    }

    public function send(array $config, AlertMessage $message): void
    {
        $title = self::escape($message->headline());
        $heading = $message->url ? "*<{$message->url}|{$title}>*" : "*{$title}*";
        $text = $heading.($message->body !== '' ? "\n".self::escape($message->body) : '')
            .($message->url && $message->action ? "\n<{$message->url}|".self::escape("Suggested fix: {$message->action}").'>' : '');

        $context = collect($message->context)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $key) => self::escape("{$key}: ".(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value)))
            ->prepend(self::escape("{$message->severity->label()} · {$message->type}"))
            ->take(10)
            ->map(fn (string $line) => ['type' => 'mrkdwn', 'text' => $line])
            ->values()
            ->all();

        $this->post((string) $config['webhook_url'], [
            'text' => $message->headline(),
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr($text, 0, 3000)]],
                ['type' => 'context', 'elements' => $context],
            ],
        ]);
    }

    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
