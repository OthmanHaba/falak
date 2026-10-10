<?php

namespace Falak\Alerting\Application\Actions;

use Falak\Alerting\Application\DefaultRulePack;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Makes a channel the organization's default: rules of the default rule pack created from now on route to it, and the
 * pack rules without any channel get it. Rules that already route somewhere keep their channels.
 */
final class MakeDefaultChannel
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly DefaultRulePack $pack,
    ) {}

    public function __invoke(Channel $channel): void
    {
        DB::transaction(function () use ($channel) {
            Channel::query()->where('organization_id', $channel->organization_id)->whereKeyNot($channel->id)->update(['is_default' => false]);
            $channel->forceFill(['is_default' => true])->save();
        });

        $this->pack->attachDefaultChannel($channel);

        $this->audit->record('alerting.channel.default', 'alerting_channel', $channel->id, ['name' => $channel->name], $channel->organization_id);
    }
}
