<?php

namespace Falak\Previews\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Previews\Application\PreviewHosts;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Save a project's preview settings. Enabling pins the webhooks of the base environment's repositories (their pull
 * request events must keep coming whatever push-to-deploy does).
 */
final class SavePreviewSettings
{
    public const MAX_CONCURRENT = 50;

    public function __construct(
        private readonly ProjectDirectory $projects,
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
        private readonly SourceControlGateway $sourceControl,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return list<string> warnings
     *
     * @throws ValidationException
     */
    public function __invoke(string $projectId, array $input, ?string $userId): array
    {
        $project = $this->projects->find($projectId) ?? throw ValidationException::withMessages(['project' => 'Unknown project.']);
        $environments = $this->projects->environments($project->id);
        $regular = array_values(array_filter($environments, fn ($e) => ! $e->isPreview));

        $data = Validator::make($input, [
            'enabled' => ['required', 'boolean'],
            'base_environment_id' => ['nullable', 'required_if:enabled,true', Rule::in(array_map(fn ($e) => $e->id, $regular))],
            'services' => ['sometimes', 'nullable', 'array', 'max:100'],
            'services.*' => [Rule::in([PreviewSettings::INCLUDE, PreviewSettings::SHARE, PreviewSettings::OMIT])],
            'server_id' => ['nullable', 'string', 'size:26'],
            'domain_pattern' => ['sometimes', 'string', 'max:100'],
            'databases' => ['sometimes', 'nullable', 'array', 'max:50'],
            'databases.*.strategy' => ['required', Rule::in([PreviewSettings::EMPTY, PreviewSettings::CLONE_BACKUP, PreviewSettings::CLONE_SANITIZE])],
            'databases.*.source_environment_id' => ['nullable', Rule::in(array_map(fn ($e) => $e->id, $regular))],
            'databases.*.sanitize_kind' => ['nullable', 'required_if:databases.*.strategy,'.PreviewSettings::CLONE_SANITIZE, Rule::in(['sql', 'command'])],
            'databases.*.sanitize_script' => ['nullable', 'required_if:databases.*.strategy,'.PreviewSettings::CLONE_SANITIZE, 'string', 'max:65536'],
            'max_concurrent' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_CONCURRENT],
            'idle_ttl_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'access' => ['sometimes', Rule::in(['basic', 'public'])],
        ], [
            'base_environment_id.in' => 'Pick an environment of this project (not a preview).',
            'databases.*.sanitize_script.required_if' => 'Production data needs a sanitize script: the preview never starts without it succeeding.',
        ])->validate();

        if (isset($data['domain_pattern']) && ! PreviewHosts::validPattern($data['domain_pattern'])) {
            throw ValidationException::withMessages(['domain_pattern' => 'Use lowercase letters, digits, dashes and {number}, {service} (and optionally {project}), e.g. pr-{number}-{service}.']);
        }

        if (($data['server_id'] ?? null) !== null && $this->servers->find(strtolower($data['server_id']))?->organizationId !== $project->organizationId) {
            throw ValidationException::withMessages(['server_id' => 'Pick a server of this organization.']);
        }

        $settings = PreviewSettings::query()->firstOrNew(['project_id' => $project->id], ['organization_id' => $project->organizationId]);
        $settings->forceFill([
            ...array_intersect_key($data, array_flip(['enabled', 'base_environment_id', 'services', 'server_id', 'domain_pattern', 'databases', 'max_concurrent', 'idle_ttl_hours', 'access'])),
            'server_id' => isset($data['server_id']) ? strtolower($data['server_id']) : null,
            'updated_by' => $userId,
        ])->save();

        $this->audit->record('previews.settings_saved', 'project', $project->id, [
            'enabled' => $settings->enabled,
            'base_environment_id' => $settings->base_environment_id,
            'access' => $settings->access,
            'databases' => array_map(fn ($d) => $d['strategy'] ?? null, $settings->databases ?? []),
        ], $project->organizationId);

        return $settings->enabled ? $this->pinWebhooks($settings) : [];
    }

    /**
     * @return list<string>
     */
    private function pinWebhooks(PreviewSettings $settings): array
    {
        $warnings = [];

        foreach ($this->projects->servicesIn((string) $settings->base_environment_id) as $service) {
            $site = $service->kind === ServiceKind::Site ? $this->sites->find($service->refId) : null;

            if ($site?->sourceConnectionId === null || $site->repository === null) {
                continue;
            }

            try {
                $webhook = $this->sourceControl->pinWebhook($site->sourceConnectionId, $site->repository);

                if (! $webhook->installed) {
                    $warnings[] = "Add the webhook {$webhook->url} to {$site->repository} with pull request events".($webhook->installError ? " ({$webhook->installError})" : '').'.';
                }
            } catch (SourceControlException $e) {
                $warnings[] = "{$site->repository}: {$e->getMessage()}";
            }
        }

        return array_values(array_unique($warnings));
    }
}
