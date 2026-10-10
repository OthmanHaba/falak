<?php

namespace Falak\Alerting\Application\Actions;

use Falak\Alerting\Application\DefaultRulePack;
use Falak\Alerting\Domain\Enums\ChannelType;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Infrastructure\Senders\SenderRegistry;
use Falak\Identity\Contracts\AuditLog;

final class SaveChannel
{
    public function __construct(
        private readonly SenderRegistry $senders,
        private readonly AuditLog $audit,
        private readonly DefaultRulePack $pack,
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

        // The organization's first channel becomes its default: the default rule pack (in-app only so far) routes to it.
        // With several channels and no default, someone picks one (the rules page asks).
        if ($created && Channel::query()->where('organization_id', $organizationId)->count() === 1) {
            $channel->forceFill(['is_default' => true])->save();
            $this->pack->attachDefaultChannel($channel);
        }

        $this->audit->record($created ? 'alerting.channel.created' : 'alerting.channel.updated', 'alerting_channel', $channel->id, [
            'name' => $channel->name,
            'type' => $type->value,
            'enabled' => $channel->enabled,
        ], $organizationId);

        return $channel;
    }
}
