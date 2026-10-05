<?php

namespace Falak\Alerting\Infrastructure\Senders;

use Illuminate\Support\Facades\Mail;
use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Application\Mail\AlertMail;
use Falak\Alerting\Domain\Enums\ChannelType;
use Throwable;

/** Email via the application mailer. Config: {recipients: list<email>}. */
final class EmailSender implements ChannelSender
{
    public function type(): ChannelType
    {
        return ChannelType::Email;
    }

    public function rules(): array
    {
        return [
            'config.recipients' => ['required', 'array', 'min:1', 'max:20'],
            'config.recipients.*' => ['required', 'email:rfc', 'max:255', 'distinct'],
        ];
    }

    public function secretKeys(): array
    {
        return [];
    }

    public function mask(array $config): array
    {
        return $config;
    }

    public function send(array $config, AlertMessage $message): void
    {
        try {
            Mail::to(array_values((array) $config['recipients']))->send(new AlertMail($message));
        } catch (Throwable $e) {
            throw new DeliveryFailed('Mail transport failed: '.$e->getMessage(), previous: $e);
        }
    }
}
