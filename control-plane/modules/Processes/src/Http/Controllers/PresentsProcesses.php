<?php

namespace Falak\Processes\Http\Controllers;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteHeaders;
use Falak\Telemetry\Contracts\TelemetryLinks;
use Illuminate\Http\Request;

/**
 * Props shared by the Queues, Daemons and Scheduler tabs: site header, per-server apply state and the
 * last known status of the site's programs.
 */
trait PresentsProcesses
{
    /**
     * @return array<string, mixed>
     */
    protected function shared(Request $request, SiteData $site): array
    {
        $servers = app(ServerDirectory::class);
        $access = app(OrganizationAccess::class);
        $states = ServerState::query()->whereIn('server_id', $site->serverIds())->get()->keyBy('server_id');
        $programs = [];

        foreach ($site->targets as $target) {
            /** @var ?ServerState $state */
            $state = $states->get($target->serverId);
            $status = collect($state?->process_status ?? [])->groupBy('name');

            foreach ($state?->programs ?? [] as $name => $meta) {
                if (($meta['site_id'] ?? null) !== $site->id) {
                    continue;
                }

                $programs[] = [
                    'name' => $name,
                    'kind' => $meta['kind'],
                    'label' => $meta['label'],
                    'numprocs' => $meta['numprocs'] ?? 1,
                    'server_id' => $target->serverId,
                    'applied' => in_array($name, $state->applied_programs ?? [], true),
                    'crash_looping' => in_array($name, $state->crash_looping ?? [], true),
                    'instances' => $status->get($name, collect())->map(fn (array $p) => [
                        'instance' => (int) ($p['instance'] ?? 0),
                        'state' => (string) ($p['state'] ?? 'unknown'),
                        'pid' => $p['pid'] ?? null,
                        'restarts' => $p['restarts'] ?? null,
                        'started_at' => $p['started_at'] ?? null,
                        'last_exit_code' => $p['last_exit_code'] ?? null,
                    ])->sortBy('instance')->values()->all(),
                ];
            }
        }

        $canLogs = $access->can($request->user(), $site->organizationId, 'telemetry.view');

        return [
            'site' => app(SiteHeaders::class)->for($site->id),
            'servers' => array_map(function ($target) use ($servers, $states, $canLogs, $site) {
                /** @var ?ServerState $state */
                $state = $states->get($target->serverId);

                return [
                    'id' => $target->serverId,
                    'name' => $servers->find($target->serverId)?->name ?? 'deleted server',
                    'role' => $target->role->value,
                    'target_status' => $target->status->value,
                    'proc' => ['status' => $state?->proc_status?->value, 'error' => $state?->proc_error, 'applied_at' => $state?->proc_applied_at?->toIso8601String()],
                    'cron' => ['status' => $state?->cron_status?->value, 'error' => $state?->cron_error, 'applied_at' => $state?->cron_applied_at?->toIso8601String()],
                    'status_at' => $state?->status_at?->toIso8601String(),
                    'logs_url' => $canLogs ? app(TelemetryLinks::class)->logs(['site_id' => $site->id, 'server_id' => $target->serverId]) : null,
                ];
            }, $site->targets),
            'programs' => $programs,
            'logsUrl' => $canLogs ? app(TelemetryLinks::class)->logs(['site_id' => $site->id]) : null,
            'can' => ['manage' => $access->can($request->user(), $site->organizationId, 'processes.manage')],
        ];
    }

    /**
     * @param  array<string, string>|null  $env
     * @return list<array{key: string, value: null}>
     */
    protected function envKeys(?array $env): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'value' => null], array_keys($env ?? []));
    }
}
