<?php

namespace Falak\Recovery\Application;

use Falak\Databases\Contracts\Data\DatabaseRecoveryPoint;
use Falak\Databases\Contracts\Data\InstanceRecoveryPoint;
use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Volumes\Contracts\Data\VolumeRecoveryPoint;
use Falak\Volumes\Contracts\VolumeRecovery;
use Illuminate\Validation\ValidationException;

/**
 * The dry run of "This server is gone": what was on the lost server, where it comes back from, and how much is lost
 * (time since each database's and volume's latest restorable backup). Nothing is changed.
 */
final class ServerRecoveryPlanner
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly SiteDirectory $sites,
        private readonly DatabaseRecovery $databases,
        private readonly VolumeRecovery $volumes,
        private readonly DomainRecords $domains,
        private readonly AgentDirectory $agents,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException when the target can't take over
     */
    public function plan(ServerData $lost, ?string $targetServerId): array
    {
        $target = $targetServerId !== null ? $this->servers->find(strtolower($targetServerId)) : null;
        $problems = [];
        $blocking = [];

        // A server whose agent still reports isn't gone: moving its databases now would fork their data.
        $agent = $this->agents->forServer($lost->id);
        $minutes = max(1, (int) config('recovery.lost_after_minutes', 10));
        $seen = $agent?->lastHeartbeatAt;

        if ($agent !== null && $agent->status !== AgentStatus::Revoked && $seen !== null && $seen->getTimestamp() > now()->subMinutes($minutes)->getTimestamp()) {
            $blocking[] = "{$lost->name}'s agent reported ".max(1, (int) ceil((now()->getTimestamp() - $seen->getTimestamp()) / 60))." min ago: it is not gone. Stop it (or wait until it has been silent for {$minutes} min) before recovering it.";
        }

        if ($targetServerId !== null && ($target === null || $target->organizationId !== $lost->organizationId)) {
            throw ValidationException::withMessages(['target_server_id' => 'Choose a server of this organization.']);
        }

        if ($target !== null && $target->id === $lost->id) {
            throw ValidationException::withMessages(['target_server_id' => 'Choose another server than the lost one.']);
        }

        if ($target !== null && ! $target->isActive()) {
            $problems[] = "{$target->name} is {$target->status->label()}: the recovery waits until it is active (reprovision it from its page if it needs attention).";
        }

        $sites = $this->sites->forServer($lost->id);
        $instances = $this->databases->instancesOn($lost->id);
        $volumes = $this->volumes->volumesOn($lost->id);

        if ($target !== null && ! $target->docker && ($instances !== [] || $volumes !== [])) {
            $problems[] = "{$target->name} has no Docker: database containers and Docker volumes need it.";
        }

        $domains = $this->domains->forSites(array_map(fn (SiteData $site) => $site->id, $sites));
        $names = [];

        foreach ($sites as $site) {
            $names[$site->id] = $site->name;
        }

        return [
            'lost' => self::server($lost),
            'target' => $target !== null ? self::server($target) : null,
            'problems' => $problems,
            'blocking' => $blocking,
            'sites' => array_map(fn (SiteData $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'runtime' => $site->runtime->value,
                'other_servers' => count(array_filter($site->targets, fn ($t) => $t->serverId !== $lost->id)),
            ], $sites),
            'databases' => array_map(fn (InstanceRecoveryPoint $instance) => [
                'id' => $instance->id,
                'name' => $instance->name,
                'engine' => $instance->engine,
                'version' => $instance->version,
                'pitr_enabled' => $instance->pitrEnabled,
                // PITR to the latest point when every database of it can (it is instance-wide), else the latest backups.
                'method' => $instance->databases !== [] && collect($instance->databases)->every(fn (DatabaseRecoveryPoint $p) => $p->usesPitr()) ? 'pitr' : 'backup',
                'databases' => array_map(fn (DatabaseRecoveryPoint $point) => self::databasePoint($point), $instance->databases),
                'worst_loss_seconds' => self::worst(array_map(fn (DatabaseRecoveryPoint $p) => $p->dataLossSeconds(now()->toDateTimeImmutable()), $instance->databases)),
            ], $instances),
            'volumes' => array_map(fn (VolumeRecoveryPoint $point) => [
                'id' => $point->volumeId,
                'name' => $point->name,
                'kind' => $point->kind,
                'backup_at' => $point->lastBackupAt?->format(DATE_ATOM),
                'data_loss_seconds' => $point->dataLossSeconds(now()->toDateTimeImmutable()),
                'method' => match (true) {
                    $point->backupId === null => 'none',
                    $point->customerHeld => 'manual',
                    default => 'backup',
                },
            ], $volumes),
            'domains' => array_map(fn (array $domain) => [...$domain, 'site' => $names[$domain['site_id']] ?? null], $domains),
        ];
    }

    /** @return array<string, mixed> */
    private static function databasePoint(DatabaseRecoveryPoint $point): array
    {
        return [
            'id' => $point->databaseId,
            'name' => $point->name,
            'backup_at' => $point->lastBackupAt?->format(DATE_ATOM),
            // PITR: the time since the last shipped WAL / binlog; otherwise the latest backup's age.
            'data_loss_seconds' => $point->dataLossSeconds(now()->toDateTimeImmutable()),
            'pitr_latest_at' => $point->pitrLatestAt?->format(DATE_ATOM),
            'customer_held' => $point->customerHeld,
            'pitr_enabled' => $point->pitrEnabled,
            'method' => match (true) {
                $point->usesPitr() => 'pitr',
                $point->backupId === null => 'none',
                $point->customerHeld => 'manual',
                default => 'backup',
            },
        ];
    }

    /**
     * The largest loss; null (everything lost) wins.
     *
     * @param  list<?int>  $losses
     */
    private static function worst(array $losses): ?int
    {
        if ($losses === [] || in_array(null, $losses, true)) {
            return $losses === [] ? 0 : null;
        }

        return max($losses);
    }

    /** @return array{id: string, name: string, ipv4: ?string, ipv6: ?string, status: string} */
    private static function server(ServerData $server): array
    {
        return ['id' => $server->id, 'name' => $server->name, 'ipv4' => $server->ipv4, 'ipv6' => $server->ipv6, 'status' => $server->status->value];
    }
}
