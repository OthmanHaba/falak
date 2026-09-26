<?php

namespace Kiln\Sites\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Application\Actions\RetryTarget;
use Kiln\Sites\Application\Actions\SetSiteTargets;
use Kiln\Sites\Application\Actions\UpdateLaravelSettings;
use Kiln\Sites\Application\Actions\UpdateSharedPaths;
use Kiln\Sites\Application\Actions\UpdateSite;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Data\LaravelSettings;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\Sites\Http\Requests\StoreSiteRequest;
use Kiln\SourceControl\Contracts\SourceControlGateway;

final class SiteSettingsController extends Controller
{
    use PresentsSites;

    public function __construct(private readonly ServerDirectory $servers) {}

    public function show(Request $request, Site $site, AgentDirectory $agents, SourceControlGateway $sourceControl): Response
    {
        $this->authorize('view', $site);
        $site->load('targets');

        return Inertia::render('Sites/Settings', [
            'site' => $this->header($site),
            'settings' => [
                'name' => $site->name,
                'framework' => $site->framework->value,
                'is_laravel' => $site->framework->isLaravel(),
                'runtime' => $site->runtime->value,
                'build_mode' => $site->build_mode->value,
                'php_version' => $site->php_version,
                'node_version' => $site->node_version,
                'source_connection_id' => $site->source_connection_id,
                'repository' => $site->repository,
                'branch' => $site->branch,
                'push_to_deploy' => $site->push_to_deploy,
                'web_directory' => $site->web_directory,
                'app_port' => $site->app_port,
                'docker_image' => $site->docker_image,
                'dockerfile' => $site->dockerfile,
                'compose_file' => $site->compose_file,
                'health_check_path' => $site->health_check_path,
                'test_domain_enabled' => $site->test_domain_enabled,
                'unix_user' => $site->unix_user,
                'isolated' => $site->isolated,
                'laravel' => $site->laravel->toArray(),
                'shared_paths' => array_map(fn ($path) => $path->toArray(), $site->shared_paths),
            ],
            'targets' => $this->targets($site, $this->serversById($this->servers, $site->serverIds())),
            'options' => $this->options($site->organization_id, $this->servers, $agents, $sourceControl),
            'warnings' => array_values((array) $request->session()->get('sites.warnings', [])),
            'can' => [
                'update' => $request->user()?->can('update', $site) ?? false,
                'delete' => $request->user()?->can('delete', $site) ?? false,
            ],
        ]);
    }

    public function update(Request $request, Site $site, UpdateSite $update): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', Rule::unique('sites_sites')->where('organization_id', $site->organization_id)->ignore($site->id)],
            'runtime' => ['sometimes', Rule::enum(SiteRuntime::class)],
            'build_mode' => ['sometimes', Rule::enum(BuildMode::class)],
            ...StoreSiteRequest::siteRules(),
        ]);

        $update($site, $data);

        return back()->with('sites.warnings', $update->warnings);
    }

    public function updateTargets(Request $request, Site $site, SetSiteTargets $set): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'server_ids' => ['required', 'array', 'min:1', 'max:50'],
            'server_ids.*' => ['string', 'size:26'],
            'leader_server_id' => ['required', 'string', 'size:26'],
        ]);

        $set($site, array_values(array_map('strval', $data['server_ids'])), $data['leader_server_id']);

        return back();
    }

    public function retryTarget(Site $site, SiteTarget $target, RetryTarget $retry): RedirectResponse
    {
        $this->authorize('update', $site);
        abort_unless($target->site_id === $site->id, 404);

        $target->setRelation('site', $site);
        $retry($target);

        return back();
    }

    public function sharedPaths(Request $request, Site $site, UpdateSharedPaths $update): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'paths' => ['present', 'array', 'max:50'],
            'paths.*.path' => ['required', 'string', 'max:255'],
            'paths.*.type' => ['required', Rule::in(['directory', 'file'])],
        ]);

        $update($site, array_values($data['paths']));

        return back();
    }

    public function laravel(Request $request, Site $site, UpdateLaravelSettings $update): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'scheduler' => ['required', 'boolean'],
            'horizon' => ['required', 'boolean'],
            'octane' => ['required', 'boolean'],
            'maintenance' => ['required', 'boolean'],
        ]);

        $update($site, LaravelSettings::fromArray($data), $request->user()?->getAuthIdentifier());

        return back();
    }
}
