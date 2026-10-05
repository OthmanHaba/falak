<?php

namespace Falak\Alerting\Infrastructure\Senders;

use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Domain\Enums\ChannelType;

/** Telegram bot sendMessage. Config: {bot_token, chat_id}. */
final class TelegramSender extends HttpSender
{
    public function type(): ChannelType
    {
        return ChannelType::Telegram;
    }

    public function rules(): array
    {
        return [
            'config.bot_token' => ['required', 'string', 'max:100', 'regex:/^\d+:[A-Za-z0-9_-]{20,}$/'],
            'config.chat_id' => ['required', 'string', 'max:64', 'regex:/^(-?\d+|@[A-Za-z0-9_]{5,})$/'],
        ];
    }

    public function secretKeys(): array
    {
        return ['bot_token'];
    }

    public function send(array $config, AlertMessage $message): void
    {
        $token = (string) $config['bot_token'];
        $text = '<b>'.e($message->headline()).'</b>';

        if ($message->body !== '') {
            $text .= "\n".e($message->body);
        }

        if ($message->url) {
            $text .= "\n".'<a href="'.e($message->url).'">Open in Falak</a>';
        }

        $response = $this->post(rtrim((string) config('alerting.telegram_api'), '/')."/bot{$token}/sendMessage", [
            'chat_id' => (string) $config['chat_id'],
            'text' => mb_substr($text, 0, 4096),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], secrets: [$token]);

        if ($response->json('ok') !== true) {
            throw new DeliveryFailed($this->redact('Telegram error: '.(string) ($response->json('description') ?? 'unknown'), [$token]));
        }
    }
}
