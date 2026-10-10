<?php

namespace Falak\Recovery\Application;

use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Fleet\Contracts\Enrollment;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Recovery\Domain\Models\ServerRecovery;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRelocation;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Volumes\Contracts\VolumeRecovery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs "This server is gone" (see {@see ServerRecovery} for the steps). advance() is idempotent: it starts what is
 * pending in the current step, polls what runs, and moves on once every item of a step is done; it runs right after a
 * start or retry and every minute from the scheduler (AdvanceServerRecoveries). A failed item stops the recovery
 * there until someone retries the step; nothing before it is redone.
 */
final class ServerRecoveryRunner
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly SiteDirectory $sites,
        private readonly SiteRelocation $relocation,
        private readonly DatabaseRecovery $databases,
        private readonly VolumeRecovery $volumes,
        private readonly DeploymentTrigger $deploys,
        private readonly DeploymentDirectory $deployments,
        private readonly DomainRecords $domains,
        private readonly Enrollment $enrollment,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $plan  ServerRecoveryPlanner::plan() for this target
     *
     * @throws ValidationException
     */
    public function start(ServerData $lost, ServerData $target, array $plan, ?string $actorId = null): ServerRecovery
    {
        if (ServerRecovery::query()->where('lost_server_id', $lost->id)->where('status', 'running')->exists()) {
            throw ValidationException::withMessages(['server' => "A recovery of {$lost->name} is already running."]);
        }

        if (($plan['blocking'] ?? []) !== []) {
            throw ValidationException::withMessages(['server' => implode(' ', $plan['blocking'])]);
        }

        $recovery = ServerRecovery::query()->create([
            'organization_id' => $lost->organizationId,
            'lost_server_id' => $lost->id,
            'lost_server_name' => $lost->name,
            'target_server_id' => $target->id,
            'target_server_name' => $target->name,
            'status' => 'running',
            'current_step' => 'replacement',
            'steps' => self::steps($plan, $target),
            'plan' => $plan,
            'requested_by' => $actorId,
        ]);

        // The lost server must never act again: if it comes back, its agent can't reconnect (no commands, no reports),
        // so nothing it still runs (the old containers) interferes with what moved. Re-enroll it to use it again.
        $this->enrollment->revokeServer($lost->id, "Recovered onto {$target->name}: this server was declared gone.");
        $this->audit->record('recovery.server_recovery_started', 'server', $lost->id, ['recovery_id' => $recovery->id, 'target' => $target->id], $lost->organizationId);
        $this->advance($recovery);

        return $recovery->refresh();
    }

    /** Failed items of a step go back to pending; the recovery runs again from there. */
    public function retry(ServerRecovery $recovery, string $stepKey): void
    {
        // Under the lock advance() takes: a scheduler tick never sees half-reset steps.
        Cache::lock("recovery:server-recovery:{$recovery->id}", 120)->block(30, fn () => $this->reset($recovery->refresh(), $stepKey));
        $this->audit->record('recovery.server_recovery_retried', 'server', $recovery->lost_server_id, ['recovery_id' => $recovery->id, 'step' => $stepKey], $recovery->organization_id);
        $this->advance($recovery);
    }

    private function reset(ServerRecovery $recovery, string $stepKey): void
    {
        $steps = $recovery->steps;

        foreach ($steps as $i => $step) {
            if ($step['key'] !== $stepKey) {
                continue;
            }

            if ($step['status'] !== 'failed') {
                throw ValidationException::withMessages(['step' => 'Only a failed step can be retried.']);
            }

            foreach ($step['items'] as $j => $item) {
                if ($item['state'] === 'failed') {
                    // A database retries the phase it failed in (the container, or its restore).
                    $steps[$i]['items'][$j] = [...$item, 'state' => 'pending', 'message' => null, 'retry' => true];
                }
            }

            $steps[$i]['status'] = 'running';
            $steps[$i]['message'] = null;
        }

        $recovery->forceFill(['steps' => $steps, 'status' => 'running', 'finished_at' => null])->save();
    }

    public function advance(ServerRecovery $recovery): void
    {
        Cache::lock("recovery:server-recovery:{$recovery->id}", 120)->get(function () use ($recovery) {
            $recovery->refresh();

            if (! $recovery->isRunning()) {
                return;
            }

            $steps = $recovery->steps;

            foreach ($steps as $i => $step) {
                if ($step['status'] === 'succeeded') {
                    continue;
                }

                $recovery->current_step = $step['key'];
                $steps[$i] = $this->runStep($recovery, $step);
                $recovery->forceFill(['steps' => $steps])->save();

                if ($steps[$i]['status'] !== 'succeeded') {
                    break;
                }
            }

            $statuses = array_column($steps, 'status');
            $status = match (true) {
                in_array('failed', $statuses, true) => 'failed',
                array_unique($statuses) === ['succeeded'] => 'succeeded',
                default => 'running',
            };

            $recovery->forceFill(['status' => $status, 'finished_at' => $status === 'running' ? null : now()])->save();

            if ($status !== 'running') {
                $this->audit->record("recovery.server_recovery_{$status}", 'server', $recovery->lost_server_id, ['recovery_id' => $recovery->id, 'step' => $recovery->current_step], $recovery->organization_id);
            }
        });
    }

    /**
     * @param  array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}  $step
     * @return array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}
     */
    private function runStep(ServerRecovery $recovery, array $step): array
    {
        foreach ($step['items'] as $j => $item) {
            if (in_array($item['state'], [...ServerRecovery::DONE, 'failed'], true)) {
                continue;
            }

            try {
                $step['items'][$j] = match ($step['key']) {
                    'replacement' => $this->replacement($item),
                    'sites' => $this->site($recovery, $item),
                    'databases' => $this->database($recovery, $item),
                    'volumes' => $this->volume($recovery, $item),
                    'deploy' => $this->deploy($recovery, $item),
                    'domains' => $this->domain($recovery, $item),
                };
            } catch (ValidationException $e) {
                $step['items'][$j] = [...$item, 'state' => 'failed', 'message' => (string) collect($e->errors())->flatten()->first()];
            } catch (AgentUnavailable) {
                $step['items'][$j] = [...$item, 'state' => 'failed', 'message' => "{$recovery->target_server_name}'s agent is not connected."];
            } catch (Throwable $e) {
                report($e);
                Log::warning('Server recovery step failed.', ['recovery_id' => $recovery->id, 'step' => $step['key'], 'item' => $item['id'] ?? null]);
                $step['items'][$j] = [...$item, 'state' => 'failed', 'message' => 'Unexpected error: '.mb_substr($e->getMessage(), 0, 300)];
            }
        }

        $states = array_column($step['items'], 'state');
        $step['status'] = match (true) {
            in_array('failed', $states, true) => 'failed',
            array_diff($states, ServerRecovery::DONE) === [] => 'succeeded',
            default => 'running',
        };

        return $step;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function replacement(array $item): array
    {
        $server = $this->servers->find((string) $item['id']);

        return match (true) {
            $server === null => [...$item, 'state' => 'failed', 'message' => 'The replacement server was deleted.'],
            $server->isActive() => [...$item, 'state' => 'succeeded', 'message' => null],
            in_array($server->status, [ServerStatus::Error, ServerStatus::NeedsAttention], true) => [...$item, 'state' => 'failed', 'message' => "{$server->name} is {$server->status->label()}: reprovision it, then retry."],
            default => [...$item, 'state' => 'running', 'message' => "Waiting for {$server->name} ({$server->status->label()})."],
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function site(ServerRecovery $recovery, array $item): array
    {
        if ($item['state'] === 'pending') {
            $this->relocation->replaceServer((string) $item['id'], $recovery->lost_server_id, $recovery->target_server_id);
        }

        $site = $this->sites->find((string) $item['id']);
        $target = $site?->target($recovery->target_server_id);

        return match (true) {
            $site === null => [...$item, 'state' => 'skipped', 'message' => 'The site was deleted.'],
            $target === null => [...$item, 'state' => 'failed', 'message' => "{$site->name} does not target {$recovery->target_server_name}."],
            $target->status === TargetStatus::Ready => [...$item, 'state' => 'succeeded', 'message' => null],
            $target->status === TargetStatus::Failed => [...$item, 'state' => 'failed', 'message' => $target->statusMessage ?: 'Preparing the server for it failed.'],
            default => [...$item, 'state' => 'running', 'message' => 'Preparing the server for it.'],
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function database(ServerRecovery $recovery, array $item): array
    {
        $id = (string) $item['id'];
        $phase = (string) ($item['phase'] ?? 'create');

        $pitr = ($item['method'] ?? 'backup') === 'pitr';

        if ($phase === 'create') {
            if ($item['state'] === 'pending') {
                $this->databases->relocate($id, $recovery->target_server_id, $recovery->requested_by, suspendPitr: $pitr);
                $item = [...$item, 'state' => 'running', 'retry' => false];
            }

            $progress = $this->databases->progress($id);

            if ($progress['state'] !== 'ready') {
                return [...$item, 'state' => $progress['state'] === 'failed' ? 'failed' : 'running', 'message' => $progress['message']];
            }

            $item = [...$item, 'phase' => 'restore', 'state' => 'pending', 'retry' => false];
        }

        if ($pitr) {
            return $this->pitr($recovery, $item);
        }

        if ($item['state'] === 'pending') {
            $result = $this->databases->restoreLatest($id, $recovery->requested_by);
            $item = [...$item, 'state' => 'running', 'restores' => $result['restores'], 'skipped' => $result['skipped']];
        }

        $skipped = (array) ($item['skipped'] ?? []);

        if (($item['restores'] ?? []) === []) {
            return [...$item, 'state' => $skipped !== [] ? 'manual' : 'succeeded', 'message' => $skipped !== [] ? implode(' ', $skipped) : 'Nothing to restore.'];
        }

        $progress = $this->databases->progress($id, (array) $item['restores']);

        return match ($progress['state']) {
            'succeeded' => [...$item, 'state' => $skipped !== [] ? 'manual' : 'succeeded', 'message' => $skipped !== [] ? implode(' ', $skipped) : null],
            'failed' => [...$item, 'state' => 'failed', 'message' => $progress['message']],
            default => [...$item, 'state' => 'running', 'message' => 'Restoring from the latest backup.'],
        };
    }

    /**
     * A PITR database: restored to the latest point of its shipped log, the copy swapped in, PITR on again. Without a
     * recovery point after all (the log was pruned meanwhile) it falls back to the latest backup.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function pitr(ServerRecovery $recovery, array $item): array
    {
        $id = (string) $item['id'];
        $restore = $item['restore'] ?? null;

        if ($item['state'] === 'pending' && is_string($restore) && ($item['retry'] ?? false)) {
            // A retry: a failed swap is tried again; a failed restore starts over.
            $progress = $this->databases->pitrProgress($restore, $recovery->requested_by, retry: true);
            $restore = $progress['state'] === 'failed' ? null : $restore;
            $item = [...$item, 'restore' => $restore, 'state' => $restore !== null ? 'running' : 'pending', 'retry' => false];
        }

        if ($item['state'] === 'pending') {
            try {
                $item = [...$item, 'state' => 'running', 'restore' => $this->databases->restoreToLatest($id, $recovery->requested_by)];
            } catch (ValidationException $e) {
                $why = (string) collect($e->errors())->flatten()->first();

                return $this->database($recovery, [...$item, 'method' => 'backup', 'state' => 'pending', 'note' => "PITR: {$why}"]);
            }
        }

        $progress = $this->databases->pitrProgress((string) $item['restore'], $recovery->requested_by);

        return [...$item, 'state' => $progress['state'], 'message' => $progress['message']];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function volume(ServerRecovery $recovery, array $item): array
    {
        if ($item['state'] === 'pending') {
            $item = [...$item, 'state' => 'running', 'operation' => $this->volumes->restoreOnto((string) $item['id'], $recovery->target_server_id, $recovery->requested_by)];
        }

        $progress = $this->volumes->progress((string) $item['operation']);

        return [...$item, 'state' => $progress['state'], 'message' => $progress['message']];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function deploy(ServerRecovery $recovery, array $item): array
    {
        $siteId = (string) $item['id'];
        $current = $this->deployments->currentForSites([$siteId])[$siteId] ?? null;

        if ($item['state'] === 'pending') {
            if ($current === null) {
                return [...$item, 'state' => 'skipped', 'message' => 'Never deployed: its first deploy goes to the new server.'];
            }

            // A deploy the volume hand-over already queued (after this recovery started) counts.
            $adopt = $current->createdAt->getTimestamp() >= $recovery->created_at->getTimestamp() && ! in_array($current->status, ['failed', 'cancelled'], true);
            $id = $adopt ? $current->id : $this->deploys->deploy($siteId, $recovery->requested_by, message: "Recovered from {$recovery->lost_server_name}");
            $item = [...$item, 'state' => 'running', 'deployment' => $id, 'since' => now()->getTimestamp()];
            $current = $this->deployments->currentForSites([$siteId])[$siteId] ?? $current;
        }

        if ($current === null) {
            return [...$item, 'state' => 'failed', 'message' => 'The deployment disappeared.'];
        }

        // A newer deployment than ours (someone deployed meanwhile) settles it too.
        $ours = $current->id === ($item['deployment'] ?? null) || $current->createdAt->getTimestamp() >= (int) ($item['since'] ?? 0);

        return match (true) {
            ! $ours => [...$item, 'state' => 'running', 'message' => 'Queued.'],
            $current->status === 'succeeded' => [...$item, 'state' => 'succeeded', 'message' => null],
            in_array($current->status, ['failed', 'cancelled'], true) => [...$item, 'state' => 'failed', 'message' => $current->error ?: "Deployment #{$current->number} {$current->status}."],
            default => [...$item, 'state' => 'running', 'message' => "Deployment #{$current->number}: {$current->status}."],
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function domain(ServerRecovery $recovery, array $item): array
    {
        $record = collect($this->domains->forSites([(string) $item['site_id']]))->firstWhere('name', $item['id']);
        $target = $this->servers->find($recovery->target_server_id);
        $address = implode(' / ', array_filter([$target?->ipv4, $target?->ipv6]));

        return match (true) {
            $record === null => [...$item, 'state' => 'skipped', 'message' => 'The domain was removed.'],
            $record['managed'] && $record['status'] === 'synced' => [...$item, 'state' => 'succeeded', 'message' => 'Falak moved its DNS record to the new server.'],
            $record['managed'] && $record['status'] === 'pending' => [...$item, 'state' => 'running', 'message' => 'Updating its DNS record at Cloudflare.'],
            $record['managed'] && in_array($record['status'], ['conflict', 'error'], true) => [...$item, 'state' => 'failed', 'message' => "Its Cloudflare record is in {$record['status']}: see the domain's settings."],
            default => [...$item, 'state' => 'manual', 'message' => "Point its DNS record at {$address}."],
        };
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}>
     */
    private static function steps(array $plan, ServerData $target): array
    {
        $item = fn (string $id, string $label, string $state = 'pending', ?string $message = null, array $extra = []) => ['id' => $id, 'label' => $label, 'state' => $state, 'message' => $message, ...$extra];
        $items = [
            'replacement' => [$item($target->id, $target->name)],
            'sites' => array_map(fn (array $site) => $item($site['id'], $site['name']), $plan['sites']),
            'databases' => array_map(fn (array $instance) => $item($instance['id'], $instance['name'], extra: ['phase' => 'create', 'method' => $instance['method'] ?? 'backup']), $plan['databases']),
            'volumes' => array_map(fn (array $volume) => match ($volume['method']) {
                'none' => $item($volume['id'], $volume['name'], 'skipped', 'No backup: it starts empty.'),
                'manual' => $item($volume['id'], $volume['name'], 'manual', 'Its backups use your own key: restore the latest one on the Volumes page with your age identity.'),
                default => $item($volume['id'], $volume['name']),
            }, $plan['volumes']),
            'deploy' => array_map(fn (array $site) => $item($site['id'], $site['name']), $plan['sites']),
            'domains' => array_map(fn (array $domain) => $item($domain['name'], $domain['name'], extra: ['site_id' => $domain['site_id']]), $plan['domains']),
        ];

        $steps = [];

        foreach (ServerRecovery::STEPS as $key => $title) {
            $steps[] = ['key' => $key, 'title' => $title, 'status' => 'pending', 'message' => null, 'items' => $items[$key]];
        }

        return $steps;
    }
}
