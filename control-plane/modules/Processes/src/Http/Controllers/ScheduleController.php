<?php

namespace Falak\Processes\Http\Controllers;

use Falak\Kernel\Http\Controller;
use Falak\Processes\Application\Actions\DeleteProcess;
use Falak\Processes\Application\Actions\SaveSchedule;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Http\Requests\ProcessRules;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ScheduleController extends Controller
{
    use PresentsProcesses;
    use ResolvesSite;

    /** The classic page moved into the canvas panel's Processes tab. */
    public function index(Request $request, string $site): RedirectResponse
    {
        return ProcessesController::toPanel($this->site($request, $site)->id);
    }

    public function store(Request $request, string $site, SaveSchedule $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, null, $request->validate(ProcessRules::schedule()), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Scheduled job saved; the servers are being updated.');
    }

    public function update(Request $request, string $site, string $schedule, SaveSchedule $save): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $save($site, $this->schedule($site, $schedule), $request->validate(ProcessRules::schedule()), $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Scheduled job updated.');
    }

    public function destroy(Request $request, string $site, string $schedule, DeleteProcess $delete): RedirectResponse
    {
        $site = $this->site($request, $site, 'processes.manage');
        $delete($site, $this->schedule($site, $schedule));

        return back()->with('success', 'Scheduled job removed.');
    }

    private function schedule(SiteData $site, string $id): Schedule
    {
        return Schedule::query()->where('site_id', $site->id)->findOrFail(strtolower($id));
    }
}
