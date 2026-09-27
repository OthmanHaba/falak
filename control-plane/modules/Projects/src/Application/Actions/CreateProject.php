<?php

namespace Kiln\Projects\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Events\EnvironmentCreated;
use Kiln\Projects\Events\ProjectCreated;

/**
 * Create a project with its `production` environment.
 */
final class CreateProject
{
    public const PRODUCTION = 'production';

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array{name: string, description?: ?string, icon?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(string $organizationId, ?string $userId, array $data, bool $isDefault = false): Project
    {
        $name = trim($data['name']);

        if (Project::query()->where('organization_id', $organizationId)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "A project named \"{$name}\" already exists."]);
        }

        [$project, $environment] = DB::transaction(function () use ($organizationId, $userId, $data, $name, $isDefault) {
            $project = Project::query()->create([
                'organization_id' => $organizationId,
                'name' => $name,
                'description' => ($data['description'] ?? null) ?: null,
                'icon' => ($data['icon'] ?? null) ?: null,
                'is_default' => $isDefault,
                'created_by' => $userId,
            ]);

            $environment = Environment::query()->create([
                'organization_id' => $organizationId,
                'project_id' => $project->id,
                'name' => self::PRODUCTION,
                'slug' => self::PRODUCTION,
                'is_production' => true,
                'created_by' => $userId,
            ]);

            return [$project, $environment];
        });

        $this->audit->record('project.created', 'project', $project->id, ['name' => $project->name, 'default' => $isDefault], $organizationId, $userId);

        ProjectCreated::dispatch($project->id, $organizationId, $project->name, $isDefault);
        EnvironmentCreated::dispatch($environment->id, $project->id, $organizationId, $environment->slug, true, null);

        return $project->load('environments');
    }
}
