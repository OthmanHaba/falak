<?php

namespace Kiln\Projects\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Projects\Domain\Models\Environment;

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
