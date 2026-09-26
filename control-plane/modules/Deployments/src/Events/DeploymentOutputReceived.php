<?php

namespace Kiln\Deployments\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * New deployment output lines on private-deployments.{id} ("deployment.output").
 */
final class DeploymentOutputReceived implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function __construct(
        public string $deploymentId,
        public array $lines,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("deployments.{$this->deploymentId}");
    }

    public function broadcastAs(): string
    {
        return 'deployment.output';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['deployment_id' => $this->deploymentId, 'lines' => $this->lines];
    }
}
