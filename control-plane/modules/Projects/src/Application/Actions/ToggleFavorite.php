<?php

namespace Falak\Projects\Application\Actions;

use Falak\Projects\Domain\Models\Favorite;
use Falak\Projects\Domain\Models\Project;

/**
 * Star / unstar a project for one user (Projects dashboard: favorites are pinned first).
 */
final class ToggleFavorite
{
    public function __invoke(Project $project, string $userId, bool $favorite): void
    {
        $query = Favorite::query()->where('user_id', $userId)->where('project_id', $project->id);

        if (! $favorite) {
            $query->delete();

            return;
        }

        if (! $query->exists()) {
            Favorite::query()->create(['user_id' => $userId, 'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_at' => now()]);
        }
    }
}
