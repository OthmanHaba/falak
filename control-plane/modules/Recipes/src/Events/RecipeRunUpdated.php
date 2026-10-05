<?php

namespace Falak\Recipes\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live run status for run pages, broadcast on private-recipes.runs.{runId}.
 */
final class RecipeRunUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    /**
     * @param  list<array{id: string, server_id: string, status: string, command_id: ?string, exit_code: ?int, error: ?string, duration_ms: ?int}>  $targets
     */
    public function __construct(
        public string $runId,
        public string $status,
        public array $targets,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("recipes.runs.{$this->runId}");
    }

    public function broadcastAs(): string
    {
        return 'recipe.run.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['run_id' => $this->runId, 'status' => $this->status, 'targets' => $this->targets];
    }
}
