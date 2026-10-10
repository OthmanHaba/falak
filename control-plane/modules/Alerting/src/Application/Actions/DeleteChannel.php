<?php

namespace Falak\Alerting\Application\Actions;

use Falak\Alerting\Application\DefaultRulePack;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Identity\Contracts\AuditLog;

final class DeleteChannel
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly DefaultRulePack $pack,
    ) {}

    /**
     * Deleting the default channel makes the oldest remaining one the default (and routes the pack rules left without
     * a channel to it).
     */
    public function __invoke(Channel $channel): void
    {
        $channel->delete();

        if ($channel->is_default) {
            $next = Channel::query()->where('organization_id', $channel->organization_id)->orderBy('created_at')->orderBy('id')->first();

            if ($next !== null) {
                $next->forceFill(['is_default' => true])->save();
                $this->pack->attachDefaultChannel($next);
            }
        }

        $this->audit->record('alerting.channel.deleted', 'alerting_channel', $channel->id, ['name' => $channel->name, 'type' => $channel->type->value], $channel->organization_id);
    }
}
