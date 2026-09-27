<?php

namespace Kiln\Terminal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\OrganizationDirectory;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerHeaders;
use Kiln\Terminal\Application\Actions\CloseSession;
use Kiln\Terminal\Application\Actions\OpenSession;
use Kiln\Terminal\Application\Actions\ShareSession;
use Kiln\Terminal\Domain\Enums\SessionStatus;
use Kiln\Terminal\Domain\Models\TerminalSession;
use Kiln\Terminal\Domain\Policies\TerminalSessionPolicy;

final class SessionController extends Controller
{
    use PresentsSessions;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly OrganizationDirectory $directory,
    ) {}

    public function index(Request $request, ServerDirectory $servers, TerminalSessionPolicy $policy): Response
    {
        $organizationId = $this->organization->requireId();
        $user = $request->user();
        $can = fn (string $permission) => $this->access->can($user, $organizationId, $permission);

        abort_unless(collect(TerminalSessionPolicy::PERMISSIONS)->contains(fn (string $p) => $can($p)), 403);

        $userId = (string) $user?->getAuthIdentifier();

        $live = TerminalSession::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', SessionStatus::live())
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('shared', true))
            ->latest()
            ->limit(100)
            ->get()
            ->filter(fn (TerminalSession $session) => $user !== null && $policy->canView($user, $session));

        $recordings = TerminalSession::query()
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', SessionStatus::live())
            ->when(! $can('terminal.recordings.view'), fn ($q) => $q->where('user_id', $userId))
            ->latest()
            ->limit(50)
            ->get();

        return Inertia::render('Terminal/Index', [
            'servers' => $can('terminal.open')
                ? array_values(array_map(fn (ServerData $server) => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'ipv4' => $server->ipv4,
                    'unix_user' => $server->unixUser,
                ], $servers->forOrganization($organizationId, activeOnly: true)))
                : [],
            'sessions' => $live->map(fn (TerminalSession $session) => $this->present($session, $this->directory))->values(),
            'recordings' => $recordings->map(fn (TerminalSession $session) => $this->present($session, $this->directory))->values(),
            'defaultUser' => (string) config('terminal.default_user', 'root'),
            'idleTimeout' => (int) config('terminal.idle_timeout', 900),
            'can' => ['open' => $can('terminal.open')],
        ]);
    }

    /**
     * The server page's "Terminal" tab: open a shell on this server, its live sessions and recordings.
     */
    public function server(Request $request, string $server, ServerDirectory $servers, ServerHeaders $headers, TerminalSessionPolicy $policy): Response
    {
        $organizationId = $this->organization->requireId();
        $user = $request->user();
        $can = fn (string $permission) => $this->access->can($user, $organizationId, $permission);
        $data = $servers->find($server);

        abort_if($data === null || $data->organizationId !== $organizationId, 404);
        abort_unless(collect(TerminalSessionPolicy::PERMISSIONS)->contains(fn (string $p) => $can($p)), 403);

        $userId = (string) $user?->getAuthIdentifier();

        $live = TerminalSession::query()
            ->where('server_id', $data->id)
            ->whereIn('status', SessionStatus::live())
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('shared', true))
            ->latest()
            ->limit(50)
            ->get()
            ->filter(fn (TerminalSession $session) => $user !== null && $policy->canView($user, $session));

        $recordings = TerminalSession::query()
            ->where('server_id', $data->id)
            ->whereNotIn('status', SessionStatus::live())
            ->when(! $can('terminal.recordings.view'), fn ($q) => $q->where('user_id', $userId))
            ->latest()
            ->limit(50)
            ->get();

        return Inertia::render('Terminal/Server', [
            'server' => $headers->for($data->id),
            'unixUser' => $data->unixUser,
            'sessions' => $live->map(fn (TerminalSession $session) => $this->present($session, $this->directory))->values(),
            'recordings' => $recordings->map(fn (TerminalSession $session) => $this->present($session, $this->directory))->values(),
            'defaultUser' => (string) config('terminal.default_user', 'root'),
            'idleTimeout' => (int) config('terminal.idle_timeout', 900),
            'can' => [
                'open' => $can('terminal.open') && $data->isActive(),
                'replay' => $can('terminal.recordings.view'),
            ],
            'serverActive' => $data->isActive(),
        ]);
    }

    public function store(Request $request, string $server, OpenSession $open): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'terminal.open');

        $data = $request->validate([
            'user' => ['nullable', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            'cols' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rows' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $session = $open(
            $organizationId,
            (string) $request->user()?->getAuthIdentifier(),
            $server,
            $data['user'] ?? null,
            (int) ($data['cols'] ?? 80),
            (int) ($data['rows'] ?? 24),
        );

        return to_route('terminal.sessions.show', $session);
    }

    public function show(Request $request, TerminalSession $session, AuditLog $audit, TerminalSessionPolicy $policy): Response
    {
        $this->authorize('view', $session);
        $user = $request->user();
        $isOwner = $session->isOwnedBy((string) $user?->getAuthIdentifier());

        if (! $isOwner && $session->isLive()) {
            $audit->record('terminal.session_attached', 'terminal_session', $session->id, ['server_id' => $session->server_id], $session->organization_id);
        }

        return Inertia::render('Terminal/Session', [
            'session' => $this->present($session, $this->directory),
            'isOwner' => $isOwner,
            'idleTimeout' => $session->idle_timeout_s,
            'inputMaxBytes' => (int) config('terminal.input_max_bytes', 16384),
            'can' => [
                'type' => $user !== null && $policy->canType($user, $session),
                'close' => $user?->can('close', $session) ?? false,
                'share' => $user?->can('share', $session) ?? false,
                'replay' => $user?->can('replay', $session) ?? false,
            ],
        ]);
    }

    public function share(Request $request, TerminalSession $session, ShareSession $share): RedirectResponse
    {
        $this->authorize('share', $session);
        $data = $request->validate(['shared' => ['required', 'boolean']]);

        $share($session, (bool) $data['shared']);

        return back();
    }

    public function destroy(TerminalSession $session, CloseSession $close): RedirectResponse
    {
        $this->authorize('close', $session);

        $close($session, 'closed');

        return back();
    }
}
