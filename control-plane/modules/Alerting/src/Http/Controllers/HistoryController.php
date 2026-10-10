<?php

namespace Falak\Alerting\Http\Controllers;

use Falak\Alerting\Domain\Enums\AlertOutcome;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\Delivery;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as ValidationRule;
use Inertia\Inertia;
use Inertia\Response;

final class HistoryController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function __invoke(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'alerting.view');

        $filters = $request->validate([
            'outcome' => ['nullable', ValidationRule::enum(AlertOutcome::class)],
            'type' => ['nullable', 'string', 'max:100'],
        ]);

        $alerts = Alert::query()
            ->with('deliveries.channel:id,name,type')
            ->where('organization_id', $organizationId)
            ->when($filters['outcome'] ?? null, fn ($query, $outcome) => $query->where('outcome', $outcome))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Alert $alert) => [
                'id' => $alert->id,
                'type' => $alert->type,
                'severity' => $alert->severity->value,
                'title' => $alert->title,
                'body' => $alert->body,
                'url' => $alert->url,
                'action' => $alert->action,
                'recovery' => $alert->recovery,
                'outcome' => $alert->outcome->value,
                'created_at' => $alert->created_at->toIso8601String(),
                'deliveries' => $alert->deliveries->map(fn (Delivery $delivery) => [
                    'id' => $delivery->id,
                    'channel' => $delivery->channel ? ['name' => $delivery->channel->name, 'type' => $delivery->channel->type->value] : null,
                    'status' => $delivery->status->value,
                    'attempts' => $delivery->attempts,
                    'error' => $delivery->error,
                    'sent_at' => $delivery->sent_at?->toIso8601String(),
                ])->values(),
            ]);

        return Inertia::render('Alerting/History', [
            'alerts' => $alerts,
            'filters' => array_filter($filters),
            'outcomes' => array_map(fn (AlertOutcome $outcome) => $outcome->value, AlertOutcome::cases()),
        ]);
    }
}
