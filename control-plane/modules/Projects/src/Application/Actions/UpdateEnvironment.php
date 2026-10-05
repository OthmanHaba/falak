<?php

namespace Falak\Projects\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Domain\Models\Environment;

/**
 * Rename an environment (its URL slug follows the name).
 */
final class UpdateEnvironment
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Environment $environment, string $name): Environment
    {
        $name = trim($name);

        if ($name === $environment->name) {
            return $environment;
        }

        if (Environment::query()->where('project_id', $environment->project_id)->whereKeyNot($environment->id)->whereRaw('lower(name) = ?', [Str::lower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => "An environment named \"{$name}\" already exists."]);
        }

        $before = $environment->slug;
        $environment->forceFill([
            'name' => $name,
            'slug' => CreateEnvironment::uniqueSlug($environment->project, $name, $environment->id),
        ])->save();

        $this->audit->record('project.environment_renamed', 'project', $environment->project_id, ['from' => $before, 'to' => $environment->slug], $environment->organization_id);

        return $environment;
    }
}
