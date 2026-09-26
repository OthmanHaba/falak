<?php

namespace Kiln\Sites\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Application\Actions\RunSiteCommand;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteCommand;

final class SiteCommandController extends Controller
{
    use PresentsSites;

    public function index(Request $request, Site $site, ServerDirectory $directory): Response
    {
        $this->authorize('view', $site);
        $site->load('targets');

        $servers = $this->serversById($directory, $site->serverIds());

        return Inertia::render('Sites/Commands', [
            'site' => $this->header($site),
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
        ]);
    }

    public function store(Request $request, Site $site, RunSiteCommand $run): RedirectResponse
    {
        $this->authorize('runCommands', $site);

        $data = $request->validate([
            'server_id' => ['nullable', 'string', 'size:26'],
            'command' => ['required', 'string', 'max:2000'],
        ]);

        $run($site, $data['server_id'] ?? null, $data['command'], $request->user()?->getAuthIdentifier());

        return back();
    }
}
