<?php

namespace Falak\Security\Application\Actions;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Network\Contracts\Firewalls;
use Falak\Security\Domain\Enums\AuditStatus;
use Falak\Security\Domain\Models\Audit;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerSshKeys;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Str;

/**
 * Dispatches security.audit with what the control plane knows the server should look like: the keys Falak installs,
 * the ports its firewall accepts and the unix users it manages. One audit runs per server at a time.
 */
final class StartAudit
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerSshKeys $keys,
        private readonly Firewalls $firewalls,
        private readonly SiteDirectory $sites,
    ) {}

    public function __invoke(ServerData $server, string $trigger, ?string $userId = null): ?Audit
    {
        if (! $server->isActive()) {
            return null;
        }

        $running = Audit::query()
            ->where('server_id', $server->id)
            ->where('status', AuditStatus::Running)
            ->where('created_at', '>', now()->subMinutes((int) config('security.stale_minutes', 30)))
            ->latest()
            ->first();

        if ($running !== null) {
            return $running;
        }

        $audit = new Audit([
            'organization_id' => $server->organizationId,
            'server_id' => $server->id,
            'trigger' => $trigger,
            'requested_by' => $userId,
        ]);

        try {
            $handle = $this->agents->dispatch($server->id, 'security.audit', $this->payload($server), (int) config('security.audit_timeout', 120), 'security.audit:'.$server->id.':'.Str::ulid());
            $audit->forceFill(['status' => AuditStatus::Running, 'command_id' => $handle->id]);
        } catch (AgentUnavailable) {
            $audit->forceFill(['status' => AuditStatus::Failed, 'error' => 'The server agent is not connected.']);
        }

        $audit->save();

        return $audit;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(ServerData $server): array
    {
        $users = [$server->unixUser];

        foreach ($this->sites->forServer($server->id) as $site) {
            $users[] = $site->unixUser;
        }

        return [
            // An object even without keys: the agent compares against it.
            'managed_keys' => (object) $this->keys->authorizedKeys($server->id),
            'expected_ports' => $this->firewalls->expectedPorts($server->id),
            'known_users' => array_values(array_unique(array_filter($users))),
            'ssh_port' => $this->keys->sshPort($server->id),
        ];
    }
}
