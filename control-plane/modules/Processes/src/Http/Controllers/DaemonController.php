<?php

namespace Kiln\Processes\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Kernel\Http\Controller;
use Kiln\Processes\Application\Actions\DeleteProcess;
use Kiln\Processes\Application\Actions\SaveDaemon;
use Kiln\Processes\Domain\Models\Daemon;
use Kiln\Processes\Http\Requests\ProcessRules;
use Kiln\Processes\Infrastructure\ProgramNames;
use Kiln\Sites\Contracts\Data\SiteData;

final class DaemonController extends Controller
{
    use PresentsProcesses;
    use ResolvesSite;

    public function index(Request $request, string $site): Response
    {
        $site = $this->site($request, $site);

        return Inertia::render('Processes/Daemons', [
            ...$this->shared($request, $site),
            'daemons' => Daemon::query()->where('site_id', $site->id)->orderBy('name')->get()->map(fn (Daemon $daemon) => [
                'id' => $daemon->id,
                'program' => ProgramNames::daemon($site->slug, $daemon->id),
                'name' => $daemon->name,
                'command' => $daemon->command,
                'directory' => $daemon->directory,
                'user' => $daemon->user,
                'instances' => $daemon->instances,
                'restart' => $daemon->restart,
                'stop_signal' => $daemon->stop_signal,
                'stop_timeout' => $daemon->stop_timeout,
                'env' => $this->envKeys($daemon->env),
                'server_ids' => $daemon->server_ids ?? [],
            ])->values(),
            'defaults' => ['directory' => $site->currentPath(), 'user' => $site->unixUser, 'php' => $site->runtime->isPhp() ? $site->phpBinary() : null, 'runtime' => $site->runtime->value],
            'options' => ['restart' => Daemon::RESTART_POLICIES, 'stop_signals' => Daemon::STOP_SIGNALS],
            'containerRuntime' => $site->runtime->isContainer(),
        ]);
    }

    public function store(Request $request, string $site, SaveDaemon $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, null, $request->validate(ProcessRules::daemon($site)), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Daemon saved; the servers are being updated.');
    }

    public function update(Request $request, string $site, string $daemon, SaveDaemon $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, $this->daemon($site, $daemon), $request->validate(ProcessRules::daemon($site)), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Daemon updated.');
    }

    public function destroy(Request $request, string $site, string $daemon, DeleteProcess $delete): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $delete($site, $this->daemon($site, $daemon));

        return back()->with('success', 'Daemon removed.');
    }

    private function daemon(SiteData $site, string $id): Daemon
    {
        return Daemon::query()->where('site_id', $site->id)->findOrFail(strtolower($id));
    }
}
