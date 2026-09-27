<?php

namespace Kiln\Projects\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Projects\Domain\Models\Project;

final class UpdateProject
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array{name?: string, description?: ?string, icon?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(Project $project, array $data): Project
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);

            if (Project::query()->where('organization_id', $project->organization_id)->where('name', $data['name'])->whereKeyNot($project->id)->exists()) {
                throw ValidationException::withMessages(['name' => "A project named \"{$data['name']}\" already exists."]);
            }
        }

        foreach (['description', 'icon'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ?: null;
            }
        }

        $project->fill(array_intersect_key($data, array_flip(['name', 'description', 'icon'])));
        $changed = array_keys($project->getDirty());

        if ($changed !== []) {
            $project->save();
            $this->audit->record('project.updated', 'project', $project->id, ['changed' => $changed, 'name' => $project->name], $project->organization_id);
        }

        return $project;
    }
}
