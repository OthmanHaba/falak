<?php

namespace Kiln\Insights\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Insights\Application\Actions\SaveThreshold;
use Kiln\Insights\Contracts\SiteNameResolver;
use Kiln\Insights\Domain\Enums\MonitoredEventType;
use Kiln\Insights\Domain\Enums\ThresholdMetric;
use Kiln\Insights\Domain\Models\HeartbeatMonitor;
use Kiln\Insights\Domain\Models\Threshold;
use Kiln\Kernel\Http\Controller;

final class ThresholdController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, string $siteId, SiteNameResolver $sites): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'insights.view');
        $siteId = strtolower($siteId);

        return Inertia::render('Insights/Settings', [
            'site' => ['id' => $siteId, 'name' => $sites->name($siteId)],
            'thresholds' => Threshold::query()->where('organization_id', $organizationId)->where('site_id', $siteId)->orderBy('event_type')->orderBy('created_at')->get()
                ->map(fn (Threshold $t) => $this->present($t))->values(),
            'heartbeats' => HeartbeatMonitor::query()->where('organization_id', $organizationId)->where('site_id', $siteId)->orderBy('job')->get()
                ->map(fn (HeartbeatMonitor $m) => HeartbeatController::present($m))->values(),
            'eventTypes' => collect(MonitoredEventType::cases())->map(fn (MonitoredEventType $t) => ['value' => $t->value, 'label' => $t->label()]),
            'metrics' => collect(ThresholdMetric::cases())->map(fn (ThresholdMetric $m) => ['value' => $m->value, 'label' => $m->label()]),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'insights.manage')],
        ]);
    }

    public function store(Request $request, string $siteId, SaveThreshold $save): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'insights.manage');

        $save($organizationId, strtolower($siteId), $this->validated($request), (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    public function update(Request $request, Threshold $threshold, SaveThreshold $save): RedirectResponse
    {
        $this->authorize('update', $threshold);

        $save($threshold->organization_id, $threshold->site_id, $this->validated($request), (string) $request->user()?->getAuthIdentifier(), $threshold);

        return back();
    }

    public function destroy(Request $request, Threshold $threshold, AuditLog $audit): RedirectResponse
    {
        $this->authorize('delete', $threshold);

        $threshold->delete();
        $audit->record('insights.threshold.deleted', 'insights_threshold', $threshold->id, ['site_id' => $threshold->site_id], $threshold->organization_id);

        return back();
    }

    /**
     * @return array{event_type: string, name_pattern?: ?string, metric: string, threshold_ms: float|int|string, window_minutes: int|string, min_count?: int|string|null, enabled?: bool}
     */
    private function validated(Request $request): array
    {
        /** @var array{event_type: string, name_pattern?: ?string, metric: string, threshold_ms: float|int|string, window_minutes: int|string, min_count?: int|string|null, enabled?: bool} */
        return $request->validate([
            'event_type' => ['required', Rule::enum(MonitoredEventType::class)],
            'name_pattern' => ['nullable', 'string', 'max:500'],
            'metric' => ['required', Rule::enum(ThresholdMetric::class)],
            'threshold_ms' => ['required', 'numeric', 'min:1', 'max:86400000'],
            'window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'min_count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Threshold $threshold): array
    {
        return [
            'id' => $threshold->id,
            'event_type' => $threshold->event_type->value,
            'name_pattern' => $threshold->name_pattern,
            'metric' => $threshold->metric->value,
            'threshold_ms' => $threshold->threshold_ms,
            'window_minutes' => $threshold->window_minutes,
            'min_count' => $threshold->min_count,
            'enabled' => $threshold->enabled,
            'description' => $threshold->describe(),
            'last_evaluated_at' => $threshold->last_evaluated_at?->toIso8601String(),
            'last_breached_at' => $threshold->last_breached_at?->toIso8601String(),
        ];
    }
}
