<?php

namespace Falak\Security\Http\Controllers;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Security\Application\Actions\ApplyFixes;
use Falak\Security\Application\Actions\StartAudit;
use Falak\Security\Application\Actions\UndoFix;
use Falak\Security\Domain\Enums\AuditStatus;
use Falak\Security\Domain\FixCatalogue;
use Falak\Security\Domain\Models\Audit;
use Falak\Security\Domain\Models\Finding;
use Falak\Security\Domain\Models\FixRun;
use Falak\Security\Domain\Score;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerHeaders;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SecurityController extends Controller
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    /** Organization overview: every server's score and badge. */
    public function index(Request $request, CurrentOrganization $organization): Response
    {
        $organizationId = $organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'security.view');

        $servers = $this->servers->forOrganization($organizationId);
        $latest = $this->latestAudits(array_map(fn (ServerData $s) => $s->id, $servers));

        return Inertia::render('Security/Index', [
            'servers' => collect($servers)->map(fn (ServerData $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'type_label' => $server->type->label(),
                'status' => $server->status->value,
                'ipv4' => $server->ipv4,
                'audit' => isset($latest[$server->id]) ? self::presentAudit($latest[$server->id]) : null,
            ])->values(),
            'weights' => ['fail' => Score::FAIL, 'warn' => Score::WARN],
        ]);
    }

    /** The server page's Security tab. */
    public function show(Request $request, string $server, ApplyFixes $fixes): Response
    {
        $data = $this->server($request->user(), $server);

        $audit = Audit::query()->where('server_id', $data->id)->where('status', AuditStatus::Completed)->latest('ran_at')->latest('id')->first();
        $running = Audit::query()->where('server_id', $data->id)->latest()->latest('id')->first();
        $history = Audit::query()->where('server_id', $data->id)->where('status', AuditStatus::Completed)->where('ran_at', '>', now()->subDays(30))
            ->orderBy('ran_at')->get(['score', 'ran_at', 'production_ready']);
        $runs = FixRun::query()->where('server_id', $data->id)->latest()->latest('id')->limit(50)->get();
        $user = $request->user();

        return Inertia::render('Security/Server', [
            'server' => app(ServerHeaders::class)->for($data->id),
            'audit' => $audit ? [
                ...self::presentAudit($audit),
                'findings' => $audit->findings()->get()->map(fn (Finding $f) => [
                    'id' => $f->check_id,
                    'title' => $f->title,
                    'area' => $f->area,
                    'status' => $f->status,
                    'severity' => $f->severity,
                    'evidence' => $f->evidence,
                    'fix_id' => $f->fix_id,
                    'fix_label' => $f->fix_id !== null ? FixCatalogue::find($f->fix_id)['label'] ?? null : null,
                    // The control plane's catalogue decides, not the agent's report.
                    'disruptive' => $f->fix_id !== null && (FixCatalogue::find($f->fix_id)['disruptive'] ?? true),
                ])->values(),
            ] : null,
            'latest' => $running ? ['status' => $running->status->value, 'error' => $running->error, 'created_at' => $running->created_at->toIso8601String()] : null,
            'history' => $history->map(fn (Audit $a) => ['score' => $a->score, 'ran_at' => $a->ran_at?->toIso8601String(), 'production_ready' => $a->production_ready])->values(),
            'fixes' => $runs->map(fn (FixRun $run) => [
                'id' => $run->id,
                'fix_id' => $run->fix_id,
                'label' => FixCatalogue::find($run->fix_id)['label'] ?? $run->fix_id,
                'status' => $run->status->value,
                'disruptive' => $run->disruptive,
                'message' => $run->message,
                'error' => $run->error,
                'can_undo' => $run->canUndo(),
                'applied_at' => $run->applied_at?->toIso8601String(),
                'undone_at' => $run->undone_at?->toIso8601String(),
                'created_at' => $run->created_at->toIso8601String(),
            ])->values(),
            'safeFixes' => array_values(array_filter($fixes->offered($data->id), fn (string $id) => ! (FixCatalogue::find($id)['disruptive'] ?? true))),
            'undoDays' => (int) config('security.undo_days', 7),
            'can' => [
                'fix' => $this->access->can($user, $data->organizationId, 'security.fix'),
                'audit' => $this->access->can($user, $data->organizationId, 'security.fix'),
            ],
        ]);
    }

    public function audit(Request $request, string $server, StartAudit $start, AuditLog $log): RedirectResponse
    {
        $data = $this->server($request->user(), $server, 'security.fix');
        abort_unless($data->isActive(), 422, 'The server is audited once it is active.');

        $audit = $start($data, 'manual', (string) $request->user()?->getAuthIdentifier());
        $log->record('security.audit_requested', 'server', $data->id, ['audit_id' => $audit?->id], $data->organizationId);

        return back();
    }

    public function fix(Request $request, string $server, ApplyFixes $fixes): RedirectResponse
    {
        $data = $this->server($request->user(), $server, 'security.fix');
        $validated = $request->validate([
            'fix_id' => ['required', 'string', 'max:120'],
            'confirm' => ['sometimes', 'boolean'],
            'reboot_at' => ['nullable', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
        ]);

        $fixes->one($data, $validated['fix_id'], (string) $request->user()?->getAuthIdentifier(), (bool) ($validated['confirm'] ?? false), $validated['reboot_at'] ?? null);

        return back();
    }

    public function fixAllSafe(Request $request, string $server, ApplyFixes $fixes): RedirectResponse
    {
        $data = $this->server($request->user(), $server, 'security.fix');
        $fixes->allSafe($data, (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    public function undo(Request $request, string $server, string $fix, UndoFix $undo): RedirectResponse
    {
        $data = $this->server($request->user(), $server, 'security.fix');
        $run = FixRun::query()->where('server_id', $data->id)->findOrFail($fix);
        $validated = $request->validate(['force' => ['sometimes', 'boolean']]);
        $undo($run, (string) $request->user()?->getAuthIdentifier(), (bool) ($validated['force'] ?? false));

        return back();
    }

    /**
     * @param  list<string>  $serverIds
     * @return array<string, Audit>
     */
    private function latestAudits(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }

        // ULIDs sort by creation time: the greatest id is the latest audit.
        $ids = Audit::query()->selectRaw('max(id) as id')->whereIn('server_id', $serverIds)->where('status', AuditStatus::Completed)->groupBy('server_id');

        return Audit::query()->whereIn('id', $ids)->get()->keyBy('server_id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentAudit(Audit $audit): array
    {
        return [
            'id' => $audit->id,
            'score' => $audit->score,
            'production_ready' => $audit->production_ready,
            'counts' => $audit->counts ?? [],
            'trigger' => $audit->trigger,
            'duration_ms' => $audit->duration_ms,
            'ran_at' => $audit->ran_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve a server through the Servers contract; 404 for unknown servers and other organizations, 403 without $permission.
     */
    private function server(?Authenticatable $user, string $serverId, string $permission = 'security.view'): ServerData
    {
        $server = $this->servers->find($serverId);

        abort_if($server === null || ! $this->access->can($user, $server->organizationId, 'security.view'), 404);
        $this->access->authorize($user, $server->organizationId, $permission);

        return $server;
    }
}
