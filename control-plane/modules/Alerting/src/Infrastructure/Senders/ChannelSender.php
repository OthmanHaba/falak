<?php

namespace Falak\Alerting\Infrastructure\Senders;

use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Domain\Enums\ChannelType;

/**
 * One implementation per channel type: config validation, masking for the UI, and delivery.
 */
interface ChannelSender
{
    public function type(): ChannelType;

    /**
     * Validation rules for the `config.*` input.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Config keys holding secrets: never sent to the UI and kept when left blank on update.
     *
     * @return list<string>
     */
    public function secretKeys(): array;

    /**
     * Config safe to show in the UI (secrets masked).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function mask(array $config): array;

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws DeliveryFailed
     */
    public function send(array $config, AlertMessage $message): void;
}
