<?php

namespace Kiln\Deployments\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live state for deployment pages: private-deployments.{id} ("deployment.updated") and
 * private-deployments.site.{siteId} (list pages).
 */
final class DeploymentUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(
        public string $deploymentId,
        public string $siteId,
        public string $status,
        public ?string $phase,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("deployments.{$this->deploymentId}"), new PrivateChannel("deployments.site.{$this->siteId}")];
    }

    public function broadcastAs(): string
    {
        return 'deployment.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['deployment_id' => $this->deploymentId, 'status' => $this->status, 'phase' => $this->phase];
    }
}
