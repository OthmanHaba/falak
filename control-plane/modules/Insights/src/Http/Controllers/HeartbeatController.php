<?php

namespace Falak\Insights\Http\Controllers;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Insights\Application\Actions\UpdateHeartbeatMonitor;
use Falak\Insights\Contracts\SiteNameResolver;
use Falak\Insights\Domain\Models\HeartbeatMonitor;
use Falak\Insights\Domain\Models\HeartbeatRun;
use Falak\Insights\Domain\Support\CronSchedule;
use Falak\Kernel\Http\Controller;

final class HeartbeatController extends Controller
{
    public function index(Request $request, CurrentOrganization $organization, OrganizationAccess $access, SiteNameResolver $sites): Response
    {
        $organizationId = $organization->requireId();
        $access->authorize($request->user(), $organizationId, 'insights.view');

        $monitors = HeartbeatMonitor::query()->where('organization_id', $organizationId)->orderBy('job')->get();
        $names = $sites->names(array_values(array_unique(array_filter($monitors->pluck('site_id')->all()))));

        return Inertia::render('Insights/Heartbeats', [
            'monitors' => $monitors->map(fn (HeartbeatMonitor $m) => [
                ...self::present($m),
                'site_name' => $m->site_id ? ($names[$m->site_id] ?? $m->site_id) : null,
                ...self::expectedVsActual($m),
            ])->values(),
            'defaultGraceSeconds' => (int) config('insights.heartbeats.grace_seconds', 120),
            'can' => ['manage' => $access->can($request->user(), $organizationId, 'insights.manage')],
        ]);
    }

    public function update(Request $request, HeartbeatMonitor $monitor, UpdateHeartbeatMonitor $update): RedirectResponse
    {
        $this->authorize('update', $monitor);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'grace_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'schedule' => ['sometimes', 'nullable', 'string', 'max:191', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && ! CronSchedule::isValid((string) $value)) {
                    $fail('The schedule must be a 5-field cron expression, @hourly/@daily/…, or "@every <duration>".');
                }
            }],
        ]);

        $update($monitor, $data, (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    public function destroy(HeartbeatMonitor $monitor, AuditLog $audit): RedirectResponse
    {
        $this->authorize('delete', $monitor);

        $monitor->runs()->delete();
        $monitor->delete();
        $audit->record('insights.heartbeat.deleted', 'insights_heartbeat', $monitor->id, ['job' => $monitor->job], $monitor->organization_id);

        return back();
    }

    /**
     * Expected runs (from the schedule) against recorded runs over the last 24 hours, plus a slot strip for the UI:
     * each expected run with the status of the run that answered it, or "missed".
     *
     * @return array{expected_24h: int|null, actual_24h: int, missed_24h: int|null, slots: list<array{at: string, status: string, duration_ms: int|null}>}
     */
    public static function expectedVsActual(HeartbeatMonitor $monitor, ?CarbonImmutable $now = null, int $maxSlots = 48): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $since = $now->subDay();
        $runs = $monitor->runs()->where('scheduled_at', '>=', $since)->orderBy('scheduled_at')->get();
        $cron = $monitor->enabled ? $monitor->cron() : null;

        if ($cron === null) {
            return [
                'expected_24h' => null,
                'actual_24h' => $runs->count(),
                'missed_24h' => null,
                'slots' => $runs->slice(-$maxSlots)->map(fn (HeartbeatRun $run) => ['at' => $run->scheduled_at->toIso8601String(), 'status' => $run->status, 'duration_ms' => $run->duration_ms])->values()->all(),
            ];
        }

        // Only slots whose grace period has elapsed can be missed; start at the later of 24h ago and first sight.
        $deadline = $now->subSeconds($monitor->graceSeconds());
        $start = $monitor->created_at && $monitor->created_at->greaterThan($since) ? CarbonImmutable::instance($monitor->created_at)->subMinute() : $since;
        $expected = [];

        for ($at = $cron->nextAfter($start); $at->lessThanOrEqualTo($deadline) && count($expected) < 1440; $at = $cron->nextAfter($at)) {
            $expected[] = $at;
        }

        $byMinute = $runs->keyBy(fn (HeartbeatRun $run) => CarbonImmutable::instance($run->scheduled_at)->utc()->startOfMinute()->timestamp);
        $slots = array_map(function (CarbonImmutable $at) use ($byMinute) {
            $run = $byMinute->get($at->startOfMinute()->timestamp);

            return ['at' => $at->toIso8601String(), 'status' => $run?->status ?? 'missed', 'duration_ms' => $run?->duration_ms];
        }, $expected);
        $missed = count(array_filter($slots, fn (array $slot) => $slot['status'] === 'missed'));

        return [
            'expected_24h' => count($expected),
            'actual_24h' => $runs->count(),
            'missed_24h' => $missed,
            'slots' => array_values(array_slice($slots, -$maxSlots)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(HeartbeatMonitor $monitor): array
    {
        return [
            'id' => $monitor->id,
            'job' => $monitor->job,
            'site_id' => $monitor->site_id,
            'server_id' => $monitor->server_id,
            'schedule' => $monitor->schedule,
            'timezone' => $monitor->timezone,
            'grace_seconds' => $monitor->grace_seconds,
            'enabled' => $monitor->enabled,
            'last_status' => $monitor->last_status,
            'last_exit_code' => $monitor->last_exit_code,
            'last_duration_ms' => $monitor->last_duration_ms,
            'last_run_at' => $monitor->last_run_at?->toIso8601String(),
            'next_expected_at' => $monitor->next_expected_at?->toIso8601String(),
            'missed_at' => $monitor->missed_at?->toIso8601String(),
            'healthy' => $monitor->missed_at === null && ! in_array($monitor->last_status, ['failed', 'timeout'], true),
        ];
    }
}
