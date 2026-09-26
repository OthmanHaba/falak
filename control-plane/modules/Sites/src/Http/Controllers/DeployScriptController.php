<?php

namespace Kiln\Sites\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Application\Actions\UpdateDeployScript;
use Kiln\Sites\Contracts\DeployScript;
use Kiln\Sites\Domain\Models\EnvironmentVersion;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Presets\Preset;

final class DeployScriptController extends Controller
{
    use PresentsSites;

    public function show(Request $request, Site $site): Response
    {
        $this->authorize('view', $site);
        $site->load('targets');

        $exposed = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->value('exposed');

        return Inertia::render('Sites/DeployScript', [
            'site' => $this->header($site),
            'script' => $site->deploy_script,
            'defaultScript' => Preset::for($site->framework)->deployScript."\n",
            'macros' => collect(DeployScript::MACROS)->map(fn (string $description, string $name) => ['name' => $name, 'description' => $description])->values(),
            'variables' => collect(DeployScript::VARIABLES)->map(fn (string $description, string $name) => ['name' => $name, 'description' => $description])->values(),
            'exposedEnvironment' => array_values(is_string($exposed) ? (array) json_decode($exposed, true) : (array) $exposed),
            'can' => ['update' => $request->user()?->can('update', $site) ?? false],
        ]);
    }

    public function update(Request $request, Site $site, UpdateDeployScript $update): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate(['script' => ['required', 'string', 'max:65535']]);

        $update($site, $data['script']);

        return back();
    }
}
