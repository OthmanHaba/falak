<?php

namespace Kiln\Alerting\Application\Actions;

use Kiln\Alerting\Domain\Models\Channel;
use Kiln\Identity\Contracts\AuditLog;

final class DeleteChannel
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Channel $channel): void
    {
        $channel->delete();

        $this->audit->record('alerting.channel.deleted', 'alerting_channel', $channel->id, ['name' => $channel->name, 'type' => $channel->type->value], $channel->organization_id);
    }
}
