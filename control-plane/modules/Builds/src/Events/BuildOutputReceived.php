<?php

namespace Kiln\Builds\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * New build log lines, broadcast on private-builds.{buildId} ("build.output"). Deployments copies
 * them into the deployment output (phase "build").
 */
final class BuildOutputReceived implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<array{seq: int, stream: string, data: string, at: string}>  $lines
     */
    public function __construct(
        public string $buildId,
        public ?string $deploymentId,
        public string $status,
        public array $lines,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("builds.{$this->buildId}");
    }

    public function broadcastAs(): string
    {
        return 'build.output';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['build_id' => $this->buildId, 'status' => $this->status, 'lines' => $this->lines];
    }
}
