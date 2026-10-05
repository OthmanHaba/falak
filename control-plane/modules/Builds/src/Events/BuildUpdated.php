<?php

namespace Falak\Builds\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live build status for build pages, broadcast on private-builds.{buildId} ("build.updated").
 */
final class BuildUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(
        public string $buildId,
        public string $status,
        public ?float $progress,
        public ?string $error,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("builds.{$this->buildId}");
    }

    public function broadcastAs(): string
    {
        return 'build.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['build_id' => $this->buildId, 'status' => $this->status, 'progress' => $this->progress, 'error' => $this->error];
    }
}
