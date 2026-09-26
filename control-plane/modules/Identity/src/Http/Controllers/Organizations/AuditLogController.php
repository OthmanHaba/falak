<?php

namespace Kiln\Identity\Http\Controllers\Organizations;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Kernel\Http\Controller;

final class AuditLogController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): Response
    {
        $organization = $this->organization();
        $this->authorize('viewAuditLog', $organization);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:128'],
            'actor' => ['nullable', 'string', 'max:64'],
            'subject' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $entries = AuditEntry::query()
            ->where('organization_id', $organization->id)
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $action).'%'))
            ->when($filters['actor'] ?? null, fn ($q, $actor) => $q->where('actor_id', $actor))
            ->when($filters['subject'] ?? null, fn ($q, $subject) => $q->where('subject_id', $subject))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditEntry $entry) => [
                'id' => $entry->id,
                'action' => $entry->action,
                'actor_type' => $entry->actor_type,
                'actor_id' => $entry->actor_id,
                'actor_name' => $entry->actor_name,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'context' => $entry->context ?? [],
                'ip_address' => $entry->ip_address,
                'created_at' => $entry->created_at->toIso8601String(),
            ]);

        return Inertia::render('Identity/organizations/audit-log', [
            'entries' => $entries,
            'filters' => array_filter($filters),
            'actions' => AuditEntry::query()->where('organization_id', $organization->id)->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
