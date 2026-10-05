<?php

namespace Falak\Builds\Http\Controllers;

use Falak\Builds\Application\Actions\CreateExternalBuilder;
use Falak\Builds\Application\Actions\InstallServerBuilder;
use Falak\Builds\Domain\Models\Builder;
use Falak\Builds\Domain\Policies\BuildPolicy;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class BuilderController extends Controller
{
    use PresentsBuilds;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly AuditLog $audit,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, BuildPolicy::VIEW);

        $builders = Builder::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->orderByRaw('case when organization_id is null then 0 else 1 end')->orderBy('name')->get();

        return Inertia::render('Builds/Builders', [
            'builders' => $builders->map(fn (Builder $b) => $this->builderResource($b))->values(),
            'localConfigured' => (string) config('builds.local_builder.token') !== '',
            'panelUrl' => rtrim((string) (config('fleet.panel_url') ?: config('app.url')), '/'),
            'plainToken' => $request->session()->get('builderToken'),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, BuildPolicy::MANAGE)],
        ]);
    }

    public function store(Request $request, CreateExternalBuilder $create): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('builds_builders', 'name')->where('organization_id', $organizationId)],
            'modes' => ['required', 'array', 'min:1'],
            'modes.*' => [Rule::in(['native', 'docker'])],
        ]);

        [, $token] = $create($organizationId, $data['name'], $data['modes'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('builderToken', $token);
    }

    public function update(Request $request, string $builder): RedirectResponse
    {
        $model = $this->owned($request, $builder);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $model->forceFill(['enabled' => $data['enabled']])->save();
        $this->audit->record($data['enabled'] ? 'builds.builder_enabled' : 'builds.builder_disabled', 'builder', $model->id, [], $model->organization_id);

        return back();
    }

    public function reinstall(Request $request, string $builder, InstallServerBuilder $install, ServerDirectory $servers): RedirectResponse
    {
        $model = $this->owned($request, $builder);
        $server = $model->server_id ? $servers->find($model->server_id) : null;

        if (! $server) {
            throw ValidationException::withMessages(['builder' => 'Only builders running on a Falak server can be reinstalled.']);
        }

        $install($server, (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    public function destroy(Request $request, string $builder): RedirectResponse
    {
        $model = $this->owned($request, $builder);
        $model->delete();
        $this->audit->record('builds.builder_deleted', 'builder', $model->id, ['name' => $model->name], $model->organization_id);

        return back();
    }

    private function manage(Request $request): string
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, BuildPolicy::MANAGE);

        return $organizationId;
    }

    private function owned(Request $request, string $id): Builder
    {
        $organizationId = $this->manage($request);

        // The shared control-plane builder is configured through the environment, not per organization.
        return Builder::query()->where('organization_id', $organizationId)->findOrFail($id);
    }
}
