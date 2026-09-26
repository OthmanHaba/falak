<?php

namespace Kiln\Processes\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Processes\Application\EnvInput;
use Kiln\Processes\Application\ServerConverger;
use Kiln\Processes\Domain\Models\Daemon;
use Kiln\Sites\Contracts\Data\SiteData;

/**
 * Create or update a daemon, then converge the site's servers.
 */
final class SaveDaemon
{
    public function __construct(
        private readonly ServerConverger $converger,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function __invoke(SiteData $site, ?Daemon $daemon, array $data, ?string $userId): Daemon
    {
        $daemon ??= new Daemon(['organization_id' => $site->organizationId, 'site_id' => $site->id, 'created_by' => $userId]);

        $daemon->fill([
            'name' => trim((string) $data['name']),
            'command' => trim((string) $data['command']),
            'directory' => ($data['directory'] ?? null) ? rtrim((string) $data['directory'], '/') ?: '/' : null,
            'user' => ($data['user'] ?? null) ?: null,
            'instances' => (int) $data['instances'],
            'restart' => (string) $data['restart'],
            'stop_signal' => (string) $data['stop_signal'],
            'stop_timeout' => (int) $data['stop_timeout'],
            'env' => EnvInput::merge($data['env'] ?? [], $daemon->exists ? ($daemon->env ?? []) : []),
            'server_ids' => ($data['server_ids'] ?? null) ?: null,
        ]);

        $created = ! $daemon->exists;
        $daemon->save();

        $this->audit->record($created ? 'processes.daemon_created' : 'processes.daemon_updated', 'site', $site->id, [
            'daemon_id' => $daemon->id,
            'name' => $daemon->name,
            'command' => $daemon->command,
            'user' => $daemon->user,
        ], $site->organizationId);

        $this->converger->schedule(...$site->serverIds());

        return $daemon;
    }
}
