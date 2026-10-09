<?php

namespace Falak\Processes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Limits\Contracts\LimitValidator;
use Falak\Processes\Application\EnvInput;
use Falak\Processes\Application\ServerConverger;
use Falak\Processes\Domain\Models\Worker;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a queue worker, then converge the site's servers.
 */
final class SaveWorker
{
    public function __construct(
        private readonly ServerConverger $converger,
        private readonly AuditLog $audit,
        private readonly LimitValidator $limits,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function __invoke(SiteData $site, ?Worker $worker, array $data, ?string $userId): Worker
    {
        $command = trim((string) ($data['command'] ?? '')) ?: null;

        if ($command === null && ! ($site->framework->isLaravel() && $site->runtime->isPhp())) {
            throw ValidationException::withMessages(['command' => 'Only Laravel sites run queue:work; enter the command that starts the worker.']);
        }

        $queues = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($data['queue'] ?? ''))), fn (string $q) => $q !== '')));
        $worker ??= new Worker(['organization_id' => $site->organizationId, 'site_id' => $site->id, 'created_by' => $userId]);

        $worker->fill([
            'connection' => ($data['connection'] ?? null) ?: null,
            'queue' => $queues === [] ? null : implode(',', $queues),
            'command' => $command,
            'processes' => (int) $data['processes'],
            'timeout' => (int) $data['timeout'],
            'sleep' => (int) $data['sleep'],
            'tries' => (int) $data['tries'],
            'backoff' => isset($data['backoff']) ? (int) $data['backoff'] : null,
            'max_jobs' => ($data['max_jobs'] ?? null) ? (int) $data['max_jobs'] : null,
            'max_time' => ($data['max_time'] ?? null) ? (int) $data['max_time'] : null,
            'memory' => (int) $data['memory'],
            'env' => EnvInput::merge($data['env'] ?? [], $worker->exists ? ($worker->env ?? []) : []),
            'server_ids' => ($data['server_ids'] ?? null) ?: null,
            // Bounded by the servers it runs on.
            'limits' => array_key_exists('limits', $data)
                ? ($this->limits->validate(is_array($data['limits']) ? $data['limits'] : null, ($data['server_ids'] ?? null) ?: $site->serverIds())->toArray() ?: null)
                : $worker->limits,
        ]);

        $created = ! $worker->exists;
        $worker->save();

        $this->audit->record($created ? 'processes.worker_created' : 'processes.worker_updated', 'site', $site->id, [
            'worker_id' => $worker->id,
            'queue' => $worker->queue,
            'processes' => $worker->processes,
        ], $site->organizationId);

        $this->converger->schedule(...$site->serverIds());

        return $worker;
    }
}
