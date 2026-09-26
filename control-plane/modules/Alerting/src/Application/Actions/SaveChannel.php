<?php

namespace Kiln\Alerting\Application\Actions;

use Kiln\Alerting\Domain\Enums\ChannelType;
use Kiln\Alerting\Domain\Models\Channel;
use Kiln\Alerting\Infrastructure\Senders\SenderRegistry;
use Kiln\Identity\Contracts\AuditLog;

final class SaveChannel
{
    public function __construct(
        private readonly SenderRegistry $senders,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, type?: string, enabled?: bool, config: array<string, mixed>}  $data  validated input
     */
    public function __invoke(string $organizationId, array $data, ?Channel $channel = null): Channel
    {
        $type = $channel?->type ?? ChannelType::from((string) $data['type']);
        $sender = $this->senders->for($type);
        $config = array_intersect_key((array) $data['config'], array_flip(array_map(
            fn (string $rule) => explode('.', substr($rule, 7))[0],
            array_keys($sender->rules()),
        )));

        // Blank secrets on update keep the stored value.
        foreach ($sender->secretKeys() as $key) {
            if (($config[$key] ?? null) === null || $config[$key] === '') {
                if ($channel && isset($channel->config[$key])) {
                    $config[$key] = $channel->config[$key];
                } else {
                    unset($config[$key]);
                }
            }
        }

        if (isset($config['recipients'])) {
            $config['recipients'] = array_values(array_unique(array_map('strtolower', (array) $config['recipients'])));
        }

        $channel ??= new Channel(['organization_id' => $organizationId, 'type' => $type]);
        $created = ! $channel->exists;
        $channel->fill([
            'name' => $data['name'],
            'enabled' => (bool) ($data['enabled'] ?? true),
            'config' => $config,
        ])->save();

        $this->audit->record($created ? 'alerting.channel.created' : 'alerting.channel.updated', 'alerting_channel', $channel->id, [
            'name' => $channel->name,
            'type' => $type->value,
            'enabled' => $channel->enabled,
        ], $organizationId);

        return $channel;
    }
}
