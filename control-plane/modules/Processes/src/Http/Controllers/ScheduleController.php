<?php

namespace Kiln\Processes\Http\Controllers;

use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Kernel\Http\Controller;
use Kiln\Processes\Application\Actions\DeleteProcess;
use Kiln\Processes\Application\Actions\SaveSchedule;
use Kiln\Processes\Domain\Models\Schedule;
use Kiln\Processes\Domain\Models\ServerState;
use Kiln\Processes\Http\Requests\ProcessRules;
use Kiln\Processes\Infrastructure\ProgramNames;
use Kiln\Sites\Contracts\Data\SiteData;

final class ScheduleController extends Controller
{
    use PresentsProcesses;
    use ResolvesSite;

    public function index(Request $request, string $site): Response
    {
        $site = $this->site($request, $site);
        $laravel = $site->framework->isLaravel() && $site->runtime->isPhp();
        $leader = $site->leader();
        $jobs = $leader ? (ServerState::query()->find($leader->serverId)?->jobs ?? []) : [];

        return Inertia::render('Processes/Scheduler', [
            ...$this->shared($request, $site),
            'scheduler' => [
                'available' => $laravel,
                'enabled' => $laravel && $site->laravel->scheduler,
                'job' => ProgramNames::scheduler($site->slug),
                'command' => $site->phpBinary().' artisan schedule:run',
                'deployed' => isset($jobs[ProgramNames::scheduler($site->slug)]),
            ],
            'leader' => $leader?->serverId,
            'schedules' => Schedule::query()->where('site_id', $site->id)->orderBy('name')->get()->map(fn (Schedule $schedule) => [
                'id' => $schedule->id,
                'job' => ProgramNames::cron($site->slug, $schedule->id),
                'name' => $schedule->name,
                'command' => $schedule->command,
                'expression' => $schedule->expression,
                'timezone' => $schedule->timezone,
                'user' => $schedule->user,
                'overlap' => $schedule->overlap,
                'timeout' => $schedule->timeout,
                'heartbeat' => $schedule->heartbeat,
                'enabled' => $schedule->enabled,
                'all_servers' => $schedule->all_servers,
                'deployed' => isset($jobs[ProgramNames::cron($site->slug, $schedule->id)]),
            ])->values(),
            'defaults' => ['user' => $site->unixUser, 'cwd' => $site->currentPath(), 'php' => $site->runtime->isPhp() ? $site->phpBinary() : null],
            'presets' => [
                ['label' => 'Every minute', 'value' => '* * * * *'],
                ['label' => 'Every 5 minutes', 'value' => '*/5 * * * *'],
                ['label' => 'Every 15 minutes', 'value' => '*/15 * * * *'],
                ['label' => 'Hourly', 'value' => '@hourly'],
                ['label' => 'Nightly (midnight)', 'value' => '@daily'],
                ['label' => 'Weekly', 'value' => '@weekly'],
                ['label' => 'Monthly', 'value' => '@monthly'],
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'insightsUrl' => '/insights/heartbeats',
            'containerRuntime' => $site->runtime->isContainer(),
        ]);
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
