<?php

namespace Falak\Terminal\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Terminal\Domain\Enums\SessionStatus;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Events\TerminalSessionOpened;

final class OpenSession
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly AgentGateway $agents,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(string $organizationId, string $userId, string $serverId, ?string $unixUser = null, int $cols = 80, int $rows = 24): TerminalSession
    {
        $server = $this->servers->find($serverId);

        if (! $server || $server->organizationId !== $organizationId) {
            abort(404);
        }

        if (! $server->isActive()) {
            throw ValidationException::withMessages(['server' => 'Terminals can be opened once the server is active.']);
        }

        $unixUser = $unixUser ?: (string) config('terminal.default_user', 'root');
        $idle = max(0, (int) config('terminal.idle_timeout', 900));

        return DB::transaction(function () use ($organizationId, $userId, $server, $unixUser, $cols, $rows, $idle) {
            $session = TerminalSession::query()->create([
                'organization_id' => $organizationId,
                'server_id' => $server->id,
                'server_name' => $server->name,
                'user_id' => $userId,
                'unix_user' => $unixUser,
                'status' => SessionStatus::Opening,
                'cols' => $cols,
                'rows' => $rows,
                'initial_cols' => $cols,
                'initial_rows' => $rows,
                'idle_timeout_s' => $idle,
                'shared' => false,
                'last_activity_at' => now(),
            ]);

            try {
                $handle = $this->agents->dispatch($server->id, 'terminal.open', [
                    'session_id' => $session->id,
                    'user' => $unixUser,
                    'shell' => (string) config('terminal.shell', '/bin/bash'),
                    'cols' => $cols,
                    'rows' => $rows,
                    'idle_timeout_s' => $idle,
                    'env' => ['TERM' => (string) config('terminal.term', 'xterm-256color')],
                ], (int) config('terminal.max_duration', 3600), "terminal.open:{$session->id}");
            } catch (AgentUnavailable) {
                throw ValidationException::withMessages(['server' => 'The server agent is not connected.']);
            }

            $session->forceFill(['command_id' => $handle->id])->save();

            $this->audit->record('terminal.session_opened', 'terminal_session', $session->id, [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'unix_user' => $unixUser,
            ], $organizationId);

            TerminalSessionOpened::dispatch($session->id, $organizationId, $server->id, $userId, $unixUser);

            return $session;
        });
    }
}
