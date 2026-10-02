<?php

namespace Kiln\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Application\Compose\RepoComposeInspection;
use Kiln\Sites\Contracts\ComposeServiceExtraction;
use Kiln\Sites\Contracts\ComposeSource;
use Kiln\Sites\Contracts\Data\ComposeConfig;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Http\Requests\StoreSiteRequest;
use Kiln\SourceControl\Contracts\Exceptions\NoApi;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Contracts\SourceControlGateway;

/**
 * Compose apps from a repository (docs/plans/COMPOSE_APPS.md): the compose files a repository has (suggestions)
 * and what Kiln sees in the chosen ones — services, variables, adjustments — before and after creation.
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

        try {
            $files = $inspection->candidates($data['source_connection_id'], $data['repository'], $data['branch']);
        } catch (NoApi) {
            return response()->json(['data' => ['no_api' => true, 'files' => []]]);
        } catch (SourceControlException $e) {
            throw ValidationException::withMessages(['repository' => $e->getMessage()]);
        }

        return response()->json(['data' => ['no_api' => false, 'files' => $files]]);
    }

    /** POST /sites/compose/inspect {source_connection_id, repository, branch, compose_files, compose_profiles, compose_services, compose_adjustments, public_services} */
    public function inspect(Request $request, RepoComposeInspection $inspection): JsonResponse
    {
        $data = $this->repository($request);
        $compose = $request->validate(StoreSiteRequest::composeRules());
        $config = self::config($compose);

        return response()->json(['data' => $inspection->inspect(
            $data['source_connection_id'], $data['repository'], $data['branch'], $config->files, $config->profiles, $config,
            array_values(array_filter(array_map(fn ($p) => is_array($p) ? (string) ($p['service'] ?? '') : '', (array) ($compose['public_services'] ?? [])))),
        )]);
    }

    /** POST /sites/{site}/compose/inspect — the site's repository; unsaved choices may override the saved ones. */
    public function inspectSite(Request $request, Site $site, RepoComposeInspection $inspection, ComposeServiceExtraction $extraction): JsonResponse
    {
        $this->authorize('view', $site);

        if ($site->runtime !== SiteRuntime::Compose || ($site->compose_source ?? ComposeSource::Repo) !== ComposeSource::Repo) {
            throw ValidationException::withMessages(['compose_source' => 'Only compose sites deployed from a repository read their compose files from it.']);
        }

        if ($site->source_connection_id === null || $site->repository === null) {
            throw ValidationException::withMessages(['repository' => 'Connect a repository first.']);
        }

        $compose = $request->validate(StoreSiteRequest::composeRules());
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
        )]);
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
     * @return array{source_connection_id: string, repository: string, branch: string}
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
        ]);

        $connection = $this->git->connection($data['source_connection_id']);

        if ($connection === null || $connection->organizationId !== $organizationId) {
            throw ValidationException::withMessages(['source_connection_id' => 'Unknown connection.']);
        }

        return ['source_connection_id' => $connection->id, 'repository' => $data['repository'], 'branch' => $data['branch']];
    }
}
