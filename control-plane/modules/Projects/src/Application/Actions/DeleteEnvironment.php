<?php

namespace Falak\Projects\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Domain\Models\Environment;
use Illuminate\Validation\ValidationException;

/**
 * Delete an empty, non-production environment.
 */
final class DeleteEnvironment
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Environment $environment): void
    {
        if ($environment->is_production) {
            throw ValidationException::withMessages(['environment' => 'The production environment cannot be deleted.']);
        }

        if ($environment->services()->exists()) {
            throw ValidationException::withMessages(['environment' => 'Delete the services of this environment first.']);
        }

        $environment->delete();

        $this->audit->record('project.environment_deleted', 'project', $environment->project_id, ['environment' => $environment->slug], $environment->organization_id);
    }
}
