<?php

namespace Falak\Projects\Infrastructure;

use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Application\Actions\CreateEnvironment;
use Falak\Projects\Application\Actions\LinkService;
use Falak\Projects\Contracts\PreviewEnvironments;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Illuminate\Validation\ValidationException;

final class ActionPreviewEnvironments implements PreviewEnvironments
{
    public function __construct(
        private readonly LinkService $link,
        private readonly AuditLog $audit,
    ) {}

    public function create(string $baseEnvironmentId, string $name, bool $fork, array $include, array $shared, array $siteOverrides, ?string $userId = null): array
    {
        $base = Environment::query()->with('project')->find(strtolower($baseEnvironmentId))
            ?? throw ValidationException::withMessages(['base_environment_id' => 'The base environment no longer exists.']);

        if ($base->is_preview) {
            throw ValidationException::withMessages(['base_environment_id' => 'A preview cannot be the base of another preview.']);
        }

        // Its own instance: the action keeps the warnings and copies of one run.
        $create = app(CreateEnvironment::class);
        $environment = $create($base->project, $name, $userId, $base, [
            'fork' => $fork,
            'include' => array_values($include),
            'shared' => array_values($shared),
            'sites' => $siteOverrides,
        ]);

        return ['environment' => $environment->toData(), 'sites' => $create->copies, 'warnings' => $create->warnings];
    }

    public function place(string $environmentId, ServiceKind $kind, string $refId, string $name, int $x = 0, int $y = 0): void
    {
        $environment = Environment::query()->findOrFail(strtolower($environmentId));

        ($this->link)($environment, $kind, $refId, $name, $x, $y);
    }

    public function delete(string $environmentId): void
    {
        $environment = Environment::query()->find(strtolower($environmentId));

        if ($environment === null) {
            return;
        }

        if (! $environment->is_preview) {
            throw ValidationException::withMessages(['environment' => 'Only preview environments are deleted this way.']);
        }

        // Services still placed (a database whose deletion the agent has not confirmed yet) go with it.
        $environment->delete();

        $this->audit->record('project.environment_deleted', 'project', $environment->project_id, ['environment' => $environment->slug, 'preview' => true], $environment->organization_id);
    }
}
