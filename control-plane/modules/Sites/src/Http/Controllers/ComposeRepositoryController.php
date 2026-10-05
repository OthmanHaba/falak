<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Application\Compose\RepoComposeInspection;
use Falak\Sites\Contracts\ComposeServiceExtraction;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\ComposeConfig;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Http\Requests\StoreSiteRequest;
use Falak\SourceControl\Contracts\Exceptions\NoApi;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Compose apps from a repository (docs/plans/COMPOSE_APPS.md): the compose files a repository has (suggestions)
 * and what Falak sees in the chosen ones — services, variables, adjustments — before and after creation.
 */
final class ComposeRepositoryController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SourceControlGateway $git,
    ) {}

    /** POST /sites/compose/candidates {source_connection_id, repository, branch} */
    public function candidates(Request $request, RepoComposeInspection $inspection): JsonResponse
    {
        $data = $this->repository($request);

        return $this->candidateList($inspection, $data['source_connection_id'], $data['repository'], $data['branch'], $data['root_directory']);
    }

    /** POST /sites/{site}/compose/candidates — suggestions for Settings → Compose (people who may change the site). */
    public function candidatesForSite(Site $site, RepoComposeInspection $inspection): JsonResponse
    {
        $this->authorize('update', $site);
        $this->ensureRepository($site);

        return $this->candidateList($inspection, (string) $site->source_connection_id, (string) $site->repository, (string) ($site->branch ?: 'main'), $site->root_directory);
    }

    /** POST /sites/compose/inspect {source_connection_id, repository, branch, root_directory?, compose_files, compose_profiles, compose_services, compose_adjustments, public_services} */
    public function inspect(Request $request, RepoComposeInspection $inspection): JsonResponse
    {
        $data = $this->repository($request);
        $compose = $request->validate(StoreSiteRequest::composeRules());
        $config = self::config($compose);

        return response()->json(['data' => $inspection->inspect(
            $data['source_connection_id'], $data['repository'], $data['branch'], $config->files, $config->profiles, $config,
            array_values(array_filter(array_map(fn ($p) => is_array($p) ? (string) ($p['service'] ?? '') : '', (array) ($compose['public_services'] ?? [])))),
            root: $data['root_directory'],
        )]);
    }

    /**
     * POST /sites/{site}/compose/inspect — the site's repository. People who may change the site can preview unsaved
     * choices and see the YAML and env-file values; viewers get the saved project's services and variables only.
     */
    public function inspectSite(Request $request, Site $site, RepoComposeInspection $inspection, ComposeServiceExtraction $extraction): JsonResponse
    {
        $this->authorize('view', $site);
        $this->ensureRepository($site);

        $canUpdate = $request->user()?->can('update', $site) === true;
        $compose = $canUpdate ? $request->validate(StoreSiteRequest::composeRules()) : [];

        if (! $canUpdate && $request->hasAny(['compose_files', 'compose_profiles', 'compose_services', 'compose_adjustments', 'public_services'])) {
            abort(403, 'Only people who can change the site can preview other compose files.');
        }

        $saved = $site->composeConfig();
        $config = self::config([
            'compose_files' => $compose['compose_files'] ?? $saved?->files,
            'compose_profiles' => $compose['compose_profiles'] ?? $saved?->profiles,
            'compose_services' => $compose['compose_services'] ?? $saved?->services,
            'compose_adjustments' => $compose['compose_adjustments'] ?? $saved?->adjustments,
        ]);
        $public = array_key_exists('public_services', $compose)
            ? array_values(array_filter(array_map(fn ($p) => is_array($p) ? (string) ($p['service'] ?? '') : '', (array) $compose['public_services'])))
            : array_map(fn ($p) => $p->service, $site->publicServices());

        return response()->json(['data' => $inspection->inspect(
            (string) $site->source_connection_id, (string) $site->repository, (string) ($site->branch ?: 'main'), $config->files, $config->profiles, $config, $public, $extraction->rewrites($site->id),
            root: $site->root_directory, full: $canUpdate,
        )]);
    }

    private function ensureRepository(Site $site): void
    {
        if ($site->runtime !== SiteRuntime::Compose || ($site->compose_source ?? ComposeSource::Repo) !== ComposeSource::Repo) {
            throw ValidationException::withMessages(['compose_source' => 'Only compose sites deployed from a repository read their compose files from it.']);
        }

        if ($site->source_connection_id === null || $site->repository === null) {
            throw ValidationException::withMessages(['repository' => 'Connect a repository first.']);
        }
    }

    private function candidateList(RepoComposeInspection $inspection, string $connectionId, string $repository, string $branch, ?string $root): JsonResponse
    {
        try {
            $files = $inspection->candidates($connectionId, $repository, $branch, $root);
        } catch (NoApi) {
            return response()->json(['data' => ['no_api' => true, 'files' => []]]);
        } catch (SourceControlException $e) {
            throw ValidationException::withMessages(['repository' => $e->getMessage()]);
        }

        return response()->json(['data' => ['no_api' => false, 'files' => $files]]);
    }

    /**
     * @param  array<string, mixed>  $compose
     */
    public static function config(array $compose): ComposeConfig
    {
        $files = array_values(array_map('strval', (array) ($compose['compose_files'] ?? [])));

        return new ComposeConfig(
            source: ComposeSource::Repo,
            file: $files[0] ?? null,
            publicServices: [],
            files: $files,
            profiles: array_values(array_map('strval', (array) ($compose['compose_profiles'] ?? []))),
            services: array_filter((array) ($compose['compose_services'] ?? []), fn ($d) => is_array($d) && isset($d['mode'])),
            adjustments: is_array($compose['compose_adjustments'] ?? null) ? $compose['compose_adjustments'] : [],
        );
    }

    /**
     * @return array{source_connection_id: string, repository: string, branch: string, root_directory: ?string}
     */
    private function repository(Request $request): array
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.create');

        $rules = StoreSiteRequest::siteRules();
        $data = $request->validate([
            'source_connection_id' => ['required', 'string', 'size:26'],
            'repository' => ['required', ...array_slice($rules['repository'], 2)],
            'branch' => ['required', ...array_slice($rules['branch'], 2)],
            'root_directory' => $rules['root_directory'] ?? ['nullable', 'string', 'max:255'],
        ]);

        $connection = $this->git->connection($data['source_connection_id']);

        if ($connection === null || $connection->organizationId !== $organizationId) {
            throw ValidationException::withMessages(['source_connection_id' => 'Unknown connection.']);
        }

        return ['source_connection_id' => $connection->id, 'repository' => $data['repository'], 'branch' => $data['branch'], 'root_directory' => isset($data['root_directory']) ? (string) $data['root_directory'] : null];
    }
}
