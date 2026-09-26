<?php

namespace Kiln\Insights\Http\Controllers;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Insights\Application\Actions\UpdateHeartbeatMonitor;
use Kiln\Insights\Contracts\SiteNameResolver;
use Kiln\Insights\Domain\Models\HeartbeatMonitor;
use Kiln\Insights\Domain\Support\CronSchedule;
use Kiln\Kernel\Http\Controller;

final class HeartbeatController extends Controller
{
    public function index(Request $request, CurrentOrganization $organization, OrganizationAccess $access, SiteNameResolver $sites): Response
    {
        $organizationId = $organization->requireId();
        $access->authorize($request->user(), $organizationId, 'insights.view');

        $monitors = HeartbeatMonitor::query()->where('organization_id', $organizationId)->orderBy('job')->get();
        $names = $sites->names(array_values(array_unique(array_filter($monitors->pluck('site_id')->all()))));

        return Inertia::render('Insights/Heartbeats', [
            'monitors' => $monitors->map(fn (HeartbeatMonitor $m) => [...self::present($m), 'site_name' => $m->site_id ? ($names[$m->site_id] ?? $m->site_id) : null])->values(),
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
