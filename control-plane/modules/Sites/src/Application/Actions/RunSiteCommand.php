<?php

namespace Falak\Sites\Application\Actions;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteCommand;
use Falak\Sites\Infrastructure\CommandPayloads;
use Falak\Sites\Infrastructure\SiteVariables;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Run a shell command in the site's current release on one of its servers (system.exec as the site user).
 */
final class RunSiteCommand
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Site $site, ?string $serverId, string $command, ?string $userId, string $auditAction = 'site.command_run'): SiteCommand
    {
        $site->loadMissing('targets');
        $command = trim(str_replace("\r\n", "\n", $command));
        $serverId ??= $site->leaderTarget()?->server_id;

        if ($serverId === null || ! in_array($serverId, $site->serverIds(), true)) {
            throw ValidationException::withMessages(['server_id' => 'Pick one of the site servers.']);
        }

        if ($command === '') {
            throw ValidationException::withMessages(['command' => 'Enter a command.']);
        }

        $env = SiteVariables::for($site, $serverId);

        try {
            $handle = $this->agents->dispatch(
                $serverId,
                'system.exec',
                CommandPayloads::exec($site, $command, $env),
                (int) config('sites.command_timeout', 600),
                "sites.command:{$site->id}:".Str::ulid(),
            );
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages(['server_id' => 'The server agent is not connected.']);
        }

        $record = SiteCommand::query()->create([
            'site_id' => $site->id,
            'server_id' => $serverId,
            'command' => Str::limit($command, 1990),
            'unix_user' => $site->unix_user,
            'command_id' => $handle->id,
            'status' => CommandStatus::Queued->value,
            'requested_by' => $userId,
        ]);

        $this->audit->record($auditAction, 'site', $site->id, ['server_id' => $serverId, 'command' => Str::limit($command, 200), 'command_id' => $handle->id], $site->organization_id);

        $this->prune($site);

        return $record;
    }

    private function prune(Site $site): void
    {
        $keep = (int) config('sites.command_history', 50);
        $stale = SiteCommand::query()->where('site_id', $site->id)->latest()->orderByDesc('id')->skip($keep)->take(1000)->pluck('id');

        if ($stale->isNotEmpty()) {
            SiteCommand::query()->whereIn('id', $stale)->delete();
        }
    }
}
