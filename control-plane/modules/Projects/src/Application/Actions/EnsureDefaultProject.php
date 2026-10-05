<?php

namespace Falak\Projects\Application\Actions;

use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * The organization's default project ("Default", production environment), created on first use.
 * Services created without a placement land in its production environment.
 */
final class EnsureDefaultProject
{
    public const NAME = 'Default';

    public function __construct(private readonly CreateProject $create) {}

    /**
     * @return Environment the default project's production environment
     */
    public function __invoke(string $organizationId, ?string $userId = null): Environment
    {
        $project = $this->existing($organizationId);

        if ($project === null) {
            try {
                $project = ($this->create)($organizationId, $userId, ['name' => $this->name($organizationId)], isDefault: true);
            } catch (UniqueConstraintViolationException) {
                // Created concurrently (e.g. two sites created at once for a brand-new organization).
                $project = $this->existing($organizationId) ?? throw new RuntimeException('Default project could not be created.');
            }
        }

        $project->loadMissing('environments');

        return $project->production() ?? Environment::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'name' => CreateProject::PRODUCTION,
            'slug' => CreateProject::PRODUCTION,
            'is_production' => true,
        ]);
    }

    private function existing(string $organizationId): ?Project
    {
        return Project::query()->where('organization_id', $organizationId)->where('is_default', true)->orderBy('created_at')->first();
    }

    /** "Default", or "Default 2" … when a user project already took the name. */
    private function name(string $organizationId): string
    {
        $name = self::NAME;

        for ($i = 2; Project::query()->where('organization_id', $organizationId)->where('name', $name)->exists(); $i++) {
            $name = self::NAME." {$i}";
        }

        return $name;
    }
}
