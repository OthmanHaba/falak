<?php

namespace Falak\Volumes\Application\Jobs;

use Falak\Sites\Contracts\SiteDirectory;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Every few minutes (volumes.usage_refresh_minutes): each server with volumes reports their usage (volume.inventory:
 * statfs for sized volumes, a bounded du otherwise). Shared paths are measured on their site's leader. The results
 * land in HandleCommandOutcome. With $serverId, only that server (the Refresh button).
 */
final class RefreshVolumeUsage implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public ?string $serverId = null) {}

    public function handle(AgentCommands $commands, SiteDirectory $sites): void
    {
        /** @var array<string, list<array<string, string>>> $refs */
        $refs = [];

        Volume::query()
            ->whereNotNull('server_id')
            ->whereIn('kind', [VolumeKind::Docker, VolumeKind::Sized, VolumeKind::Bind])
            ->where('status', VolumeStatus::Active)
            ->when($this->serverId, fn ($q, $id) => $q->where('server_id', $id))
            ->orderBy('id')
            ->each(function (Volume $volume) use (&$refs) {
                $refs[(string) $volume->server_id][] = $volume->ref();
            });

        Attachment::query()
            ->with('volume')
            ->where('attachable_type', AttachableType::Site)
            ->whereHas('volume', fn ($q) => $q->where('kind', VolumeKind::SharedPath)->where('options->type', '!=', 'file'))
            ->orderBy('id')
            ->each(function (Attachment $attachment) use (&$refs, $sites) {
                $leader = $sites->leader($attachment->attachable_id)?->serverId;

                if ($leader !== null && ($this->serverId === null || $this->serverId === $leader)) {
                    $refs[$leader][] = $attachment->volume->ref();
                }
            });

        $slot = now()->format('YmdHi');

        foreach ($refs as $serverId => $volumes) {
            $commands->tryDispatch($serverId, 'volume.inventory', ['volumes' => array_slice($volumes, 0, 1000), 'docker' => false], "volume.inventory:{$serverId}:{$slot}");
        }
    }
}
