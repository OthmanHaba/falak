<?php

namespace Falak\Projects\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Domain\Models\Project;
use Illuminate\Validation\ValidationException;

/**
 * Delete an empty project. Services are never deleted implicitly: delete or move them first.
 */
final class DeleteProject
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Project $project): void
    {
        if ($project->is_default) {
            throw ValidationException::withMessages(['project' => 'The default project cannot be deleted.']);
        }

        if ($project->services()->exists()) {
            throw ValidationException::withMessages(['project' => 'Delete the services of this project first.']);
        }

        $project->delete();

        $this->audit->record('project.deleted', 'project', $project->id, ['name' => $project->name], $project->organization_id);
    }
}
