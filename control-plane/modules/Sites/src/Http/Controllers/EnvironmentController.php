<?php

namespace Kiln\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\OrganizationDirectory;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Application\Actions\SaveEnvironment;
use Kiln\Sites\Domain\Dotenv;
use Kiln\Sites\Domain\Models\EnvironmentVersion;
use Kiln\Sites\Domain\Models\Site;

/**
 * Environment variables: values are only sent to the browser on an explicit, audited reveal.
 */
final class EnvironmentController extends Controller
{
    use PresentsSites;

    public function show(Request $request, Site $site, OrganizationDirectory $directory): Response
    {
        $this->authorize('view', $site);
        $site->load('targets');

        $versions = $site->environmentVersions()->limit(25)->get(['id', 'site_id', 'version', 'exposed', 'changed_keys', 'created_by', 'created_at']);
        $current = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
        $users = [];

        return Inertia::render('Sites/Environment', [
            'site' => $this->header($site),
            'current' => $current ? [
                'version' => $current->version,
                // Names only; values need a reveal.
                'keys' => array_keys($current->variables),
                'exposed' => $current->exposed,
            ] : null,
            'versions' => $versions->map(function (EnvironmentVersion $version) use ($directory, &$users) {
                $by = $version->created_by;

                if ($by !== null && ! array_key_exists($by, $users)) {
                    $users[$by] = $directory->findUser($by)?->name;
                }

                return [
                    'version' => $version->version,
                    'changed_keys' => $version->changed_keys,
                    'created_by' => $by !== null ? $users[$by] : null,
                    'created_at' => $version->created_at->toIso8601String(),
                ];
            })->values(),
            'can' => [
                'reveal' => $request->user()?->can('revealEnvironment', $site) ?? false,
                'update' => $request->user()?->can('updateEnvironment', $site) ?? false,
            ],
        ]);
    }

    public function reveal(Request $request, Site $site, AuditLog $audit): JsonResponse
    {
        $this->authorize('revealEnvironment', $site);

        $version = $request->integer('version') ?: null;
        $environment = EnvironmentVersion::query()
            ->where('site_id', $site->id)
            ->when($version, fn ($q, $v) => $q->where('version', $v), fn ($q) => $q->orderByDesc('version'))
            ->firstOrFail()
            ->toData();

        $audit->record('site.environment_revealed', 'site', $site->id, ['version' => $environment->version], $site->organization_id);

        return response()->json([
            'data' => [
                'version' => $environment->version,
                'content' => $environment->toDotenv(),
                'exposed' => $environment->exposedToDeployScript,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Site $site, SaveEnvironment $save): RedirectResponse
    {
        $this->authorize('updateEnvironment', $site);

        $data = $request->validate([
            'content' => ['present', 'nullable', 'string', 'max:65535'],
            'exposed' => ['present', 'array'],
            'exposed.*' => ['string', 'regex:'.Dotenv::KEY_PATTERN],
            'base_version' => ['nullable', 'integer'],
        ]);

        try {
            $variables = Dotenv::parse((string) $data['content']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['content' => $e->getMessage()]);
        }

        $latest = EnvironmentVersion::query()->where('site_id', $site->id)->max('version');

        if (isset($data['base_version']) && $latest !== null && (int) $data['base_version'] !== (int) $latest) {
            throw ValidationException::withMessages(['content' => "Someone saved version {$latest} while you were editing. Reveal again to merge your changes."]);
        }

        $save($site, $variables, array_values($data['exposed']), $request->user()?->getAuthIdentifier());

        return back();
    }

    public function restore(Request $request, Site $site, int $version, SaveEnvironment $save): RedirectResponse
    {
        $this->authorize('updateEnvironment', $site);

        $old = EnvironmentVersion::query()->where('site_id', $site->id)->where('version', $version)->firstOrFail();

        $save($site, array_map('strval', $old->variables), $old->exposed, $request->user()?->getAuthIdentifier(), 'site.environment_restored');

        return back();
    }
}
