<?php

namespace Falak\Alerting\Application\Actions;

use Falak\Alerting\Application\DefaultRulePack;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;

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
        $next = DB::transaction(function () use ($channel) {
            // Serializes with another delete or "Make default": the organization keeps at most one default.
            $remaining = Channel::query()->where('organization_id', $channel->organization_id)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $channel->delete();

            if (! $channel->is_default || $remaining->contains(fn (Channel $other) => $other->id !== $channel->id && $other->is_default)) {
                return null;
            }

            $next = $remaining->first(fn (Channel $other) => $other->id !== $channel->id);
            $next?->forceFill(['is_default' => true])->save();

            return $next;
        });

        if ($next !== null) {
            $this->pack->attachDefaultChannel($next);
        }

        $this->audit->record('alerting.channel.deleted', 'alerting_channel', $channel->id, ['name' => $channel->name, 'type' => $channel->type->value], $channel->organization_id);
    }
}
