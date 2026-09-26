<?php

namespace Kiln\Processes\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Kernel\Http\Controller;
use Kiln\Processes\Application\Actions\DeleteProcess;
use Kiln\Processes\Application\Actions\SaveWorker;
use Kiln\Processes\Domain\Models\Worker;
use Kiln\Processes\Http\Requests\ProcessRules;
use Kiln\Processes\Infrastructure\ProgramNames;
use Kiln\Processes\Infrastructure\StateCompiler;
use Kiln\Sites\Contracts\Data\SiteData;

final class QueueController extends Controller
{
    use PresentsProcesses;
    use ResolvesSite;

    public function index(Request $request, string $site): Response
    {
        $site = $this->site($request, $site);
        $laravel = $site->framework->isLaravel() && $site->runtime->isPhp();

        return Inertia::render('Processes/Queues', [
            ...$this->shared($request, $site),
            'workers' => Worker::query()->where('site_id', $site->id)->orderBy('created_at')->orderBy('id')->get()->map(fn (Worker $worker) => [
                'id' => $worker->id,
                'program' => ProgramNames::worker($site->slug, $worker->id),
                'label' => StateCompiler::workerLabel($worker),
                'connection' => $worker->connection,
                'queue' => $worker->queue,
                'command' => $worker->command,
                'processes' => $worker->processes,
                'timeout' => $worker->timeout,
                'sleep' => $worker->sleep,
                'tries' => $worker->tries,
                'backoff' => $worker->backoff,
                'max_jobs' => $worker->max_jobs,
                'max_time' => $worker->max_time,
                'memory' => $worker->memory,
                'env' => $this->envKeys($worker->env),
                'server_ids' => $worker->server_ids ?? [],
            ])->values(),
            'laravel' => [
                'available' => $laravel,
                'horizon' => $laravel && $site->laravel->horizon,
                'octane' => $laravel && $site->laravel->octane,
                'octane_port' => $laravel && $site->laravel->octane ? StateCompiler::octanePort($site) : null,
                'horizon_program' => ProgramNames::horizon($site->slug),
                'octane_program' => ProgramNames::octane($site->slug),
            ],
            'runsArtisan' => $laravel,
            'containerRuntime' => $site->runtime->isContainer(),
        ]);
    }

    public function store(Request $request, string $site, SaveWorker $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, null, $request->validate(ProcessRules::worker($site)), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Queue worker saved; the servers are being updated.');
    }

    public function update(Request $request, string $site, string $worker, SaveWorker $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, $this->worker($site, $worker), $request->validate(ProcessRules::worker($site)), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Queue worker updated.');
    }

    public function destroy(Request $request, string $site, string $worker, DeleteProcess $delete): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $delete($site, $this->worker($site, $worker));

        return back()->with('success', 'Queue worker removed.');
    }

    private function worker(SiteData $site, string $id): Worker
    {
        return Worker::query()->where('site_id', $site->id)->findOrFail(strtolower($id));
    }
}
