<?php

namespace Falak\Previews\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Previews\Application\PreviewHosts;
use Falak\Previews\Application\PreviewServers;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Projects\Contracts\Data\EnvironmentData;
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
        private readonly PreviewServers $previewServers,
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
            'fork_server_id' => ['nullable', 'string', 'size:26'],
            'variables' => ['sometimes', 'nullable', 'array', 'max:200'],
            'variables.*' => ['string', 'regex:/^[A-Za-z_][A-Za-z0-9_]{0,127}$/'],
            'acknowledge_shared_database' => ['sometimes', 'boolean'],
            'domain_pattern' => ['sometimes', 'string', 'max:100'],
            'databases' => ['sometimes', 'nullable', 'array', 'max:50'],
            'databases.*.strategy' => ['required', Rule::in([PreviewSettings::EMPTY, PreviewSettings::CLONE_BACKUP, PreviewSettings::CLONE_SANITIZE])],
            'databases.*.source_environment_id' => ['nullable', Rule::in(array_map(fn ($e) => $e->id, $regular))],
            'databases.*.sanitize_kind' => ['nullable', 'required_if:databases.*.strategy,'.PreviewSettings::CLONE_SANITIZE, Rule::in(['sql', 'command'])],
            'databases.*.sanitize_script' => ['nullable', 'required_if:databases.*.strategy,'.PreviewSettings::CLONE_SANITIZE, 'string', 'max:65536'],
            'databases.*.acknowledge_production' => ['sometimes', 'boolean'],
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

        foreach (['server_id', 'fork_server_id'] as $field) {
            if (($data[$field] ?? null) !== null && $this->servers->find(strtolower($data[$field]))?->organizationId !== $project->organizationId) {
                throw ValidationException::withMessages([$field => 'Pick a server of this organization.']);
            }
        }

        if (($data['fork_server_id'] ?? null) !== null && ($problem = $this->previewServers->forkProblem($data['fork_server_id'])) !== null) {
            throw ValidationException::withMessages(['fork_server_id' => $problem]);
        }

        $this->checkDataExposure($data, $environments);

        $settings = PreviewSettings::query()->firstOrNew(['project_id' => $project->id], ['organization_id' => $project->organizationId]);
        $settings->forceFill([
            ...array_intersect_key($data, array_flip(['enabled', 'base_environment_id', 'services', 'server_id', 'domain_pattern', 'databases', 'max_concurrent', 'idle_ttl_hours', 'access', 'variables', 'acknowledge_shared_database'])),
            'server_id' => isset($data['server_id']) ? strtolower($data['server_id']) : null,
            'fork_server_id' => isset($data['fork_server_id']) ? strtolower($data['fork_server_id']) : null,
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
     * Real data reaches previews only on purpose: sharing a database of the base environment, or cloning a production
     * backup without sanitizing, each need the project's explicit acknowledgement. (Forks never get either: they share
     * nothing and only get empty or sanitized databases.)
     *
     * @param  array<string, mixed>  $data
     * @param  list<EnvironmentData>  $environments
     *
     * @throws ValidationException
     */
    private function checkDataExposure(array $data, array $environments): void
    {
        $base = $data['base_environment_id'] ?? null;
        $databaseServices = $base !== null
            ? array_map(fn ($s) => $s->name, array_filter($this->projects->servicesIn($base), fn ($s) => $s->kind === ServiceKind::Database))
            : [];

        foreach ((array) ($data['services'] ?? []) as $name => $mode) {
            if ($mode === PreviewSettings::SHARE && in_array($name, $databaseServices, true) && ! ($data['acknowledge_shared_database'] ?? false)) {
                throw ValidationException::withMessages(['acknowledge_shared_database' => "Sharing {$name} means previews read and write the base environment's database: confirm it."]);
            }
        }

        $production = collect($environments)->firstWhere('isProduction', true)?->id;

        foreach ((array) ($data['databases'] ?? []) as $name => $database) {
            $source = $database['source_environment_id'] ?? $base;

            if (($database['strategy'] ?? null) === PreviewSettings::CLONE_BACKUP && $source !== null && $source === $production && ! ($database['acknowledge_production'] ?? false)) {
                throw ValidationException::withMessages(["databases.{$name}.acknowledge_production" => "Previews would hold an unsanitized copy of production's {$name}: confirm it, or use “Production, sanitized”."]);
            }
        }
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
