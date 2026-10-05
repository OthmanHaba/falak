<?php

namespace Falak\Alerting\Application\Jobs;

use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Domain\Enums\DeliveryStatus;
use Falak\Alerting\Domain\Models\Delivery;
use Falak\Alerting\Infrastructure\Senders\DeliveryFailed;
use Falak\Alerting\Infrastructure\Senders\SenderRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one alert to one channel. A failed attempt marks the delivery failed (with the error)
 * and is retried with backoff; a later success flips it to sent.
 */
final class DeliverAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $deliveryId) {}

    public function handle(SenderRegistry $senders): void
    {
        $delivery = Delivery::query()->with(['alert', 'channel'])->find($this->deliveryId);

        if (! $delivery || $delivery->status === DeliveryStatus::Sent) {
            return;
        }

        $channel = $delivery->channel;
        $delivery->attempts++;

        if (! $channel->enabled) {
            $delivery->forceFill(['status' => DeliveryStatus::Failed, 'error' => 'Channel is disabled.'])->save();

            return;
        }

        try {
            $senders->for($channel->type)->send($channel->config, AlertMessage::fromAlert($delivery->alert));
        } catch (Throwable $e) {
            if (! $e instanceof DeliveryFailed) {
                report($e);
            }

            $error = Str::limit($e instanceof DeliveryFailed ? $e->getMessage() : 'Unexpected error ('.class_basename($e).').', 990);
            $delivery->forceFill(['status' => DeliveryStatus::Failed, 'error' => $error])->save();
            $channel->forceFill(['last_error' => $error])->save();

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);
            }

            return;
        }

        $delivery->forceFill(['status' => DeliveryStatus::Sent, 'error' => null, 'sent_at' => now()])->save();
        $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();
    }
}
