<?php

namespace Kiln\Projects\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Projects\Domain\Models\Project;

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
