<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Application\Actions\RunSiteCommand;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SiteCommandController extends Controller
{
    use PresentsSites;

    /** JSON for the Settings tab's Commands section; a browser visit opens that section. */
    public function index(Request $request, Site $site, ServerDirectory $directory): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $site);

        if (! $this->wantsPanelJson($request)) {
            return $this->toPanel($site, 'settings', 'commands');
        }

        $site->load('targets');

        $servers = $this->serversById($directory, $site->serverIds());

        return response()->json(['data' => [
            'targets' => $this->targets($site, $servers),
            'commands' => $site->commands()->limit((int) config('sites.command_history', 50))->get()->map(fn (SiteCommand $command) => [
                'id' => $command->id,
                'command' => $command->command,
                'server_id' => $command->server_id,
                'server_name' => $servers[$command->server_id]->name ?? 'deleted server',
                'unix_user' => $command->unix_user,
                'command_id' => $command->command_id,
                'status' => $command->status,
                'exit_code' => $command->exit_code,
                'created_at' => $command->created_at->toIso8601String(),
                'finished_at' => $command->finished_at?->toIso8601String(),
            ])->values(),
            'phpBinary' => $site->runtime->isPhp() ? $site->phpBinary() : null,
            'isLaravel' => $site->framework->isLaravel(),
            'currentPath' => $site->currentPath(),
            'can' => ['run' => $request->user()?->can('runCommands', $site) ?? false],
        ]]);
    }

    public function store(Request $request, Site $site, RunSiteCommand $run): RedirectResponse|JsonResponse
    {
        $this->authorize('runCommands', $site);

        $data = $request->validate([
            'server_id' => ['nullable', 'string', 'size:26'],
            'command' => ['required', 'string', 'max:2000'],
        ]);

        $command = $run($site, $data['server_id'] ?? null, $data['command'], $request->user()?->getAuthIdentifier());

        return $this->wantsPanelJson($request) ? response()->json(['data' => ['id' => $command->id, 'command_id' => $command->command_id]], 201) : back();
    }
}
