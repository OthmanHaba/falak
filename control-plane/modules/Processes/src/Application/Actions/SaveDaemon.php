<?php

namespace Falak\Processes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Limits\Contracts\LimitDefaults;
use Falak\Limits\Contracts\LimitValidator;
use Falak\Processes\Application\EnvInput;
use Falak\Processes\Application\ServerConverger;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Sites\Contracts\Data\SiteData;

/**
 * Create or update a daemon, then converge the site's servers.
 */
final class SaveDaemon
{
    public function __construct(
        private readonly ServerConverger $converger,
        private readonly AuditLog $audit,
        private readonly LimitValidator $limits,
        private readonly LimitDefaults $defaults,
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
            // Bounded by the servers it runs on. A new one starts with its environment's defaults under what was given
            // (validated together); later changes replace them.
            'limits' => array_key_exists('limits', $data) || ! $daemon->exists
                ? ($this->limits->validate(is_array($data['limits'] ?? null) ? $data['limits'] : null, ($data['server_ids'] ?? null) ?: $site->serverIds(),
                    base: $daemon->exists ? null : $this->defaults->forSite($site->id))->toArray() ?: null)
                : $daemon->limits,
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
