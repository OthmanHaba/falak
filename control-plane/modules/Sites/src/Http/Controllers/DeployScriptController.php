<?php

namespace Kiln\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Application\Actions\UpdateDeployScript;
use Kiln\Sites\Contracts\DeployScript;
use Kiln\Sites\Domain\Models\EnvironmentVersion;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Presets\Preset;

final class DeployScriptController extends Controller
{
    use PresentsSites;

    /** JSON for the Settings tab's deploy script editor; a browser visit opens that section. */
    public function show(Request $request, Site $site): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $site);

        if (! $this->wantsPanelJson($request)) {
            return $this->toPanel($site, 'settings', 'deploy');
        }

        $exposed = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->value('exposed');

        return response()->json(['data' => [
            'script' => $site->deploy_script,
            'defaultScript' => Preset::for($site->framework)->deployScript."\n",
            'macros' => collect(DeployScript::MACROS)->map(fn (string $description, string $name) => ['name' => $name, 'description' => $description])->values(),
            'variables' => collect(DeployScript::VARIABLES)->map(fn (string $description, string $name) => ['name' => $name, 'description' => $description])->values(),
            'exposedEnvironment' => array_values(is_string($exposed) ? (array) json_decode($exposed, true) : (array) $exposed),
            'can' => ['update' => $request->user()?->can('update', $site) ?? false],
        ]]);
    }

    public function update(Request $request, Site $site, UpdateDeployScript $update): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validate(['script' => ['required', 'string', 'max:65535']]);

        $update($site, $data['script']);

        return back();
    }
}
