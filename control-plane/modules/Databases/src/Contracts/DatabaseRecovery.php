<?php

namespace Falak\Databases\Contracts;

use Falak\Databases\Contracts\Data\DatabaseRecoveryPoint;
use Falak\Databases\Contracts\Data\InstanceRecoveryPoint;
use Illuminate\Validation\ValidationException;

/**
 * What a lost server's databases can come back from, and bringing them back on another server (the Recovery module's
 * "This server is gone" wizard and its readiness score).
 */
interface DatabaseRecovery
{
    /**
     * The database containers on a server (retired ones excluded), each with its databases' latest restorable backups.
     *
     * @return list<InstanceRecoveryPoint>
     */
    public function instancesOn(string $serverId): array;

    /**
     * @param  list<string>  $databaseIds
     * @return array<string, DatabaseRecoveryPoint> keyed by database id (unknown ids are omitted)
     */
    public function points(array $databaseIds): array;

    /**
     * The instance moves to $targetServerId: a new data volume and host port there, the same id, name, DNS name,
     * password, settings, databases, users, grants and schedules; db.instance.create recreates its container, then
     * its databases and users (empty). The old server is not contacted.
     *
     * Called again for an instance that already moved there, it retries what failed (the container, or its databases
     * and users). $suspendPitr: shipping stays off on the empty container (PITR restore follows: restoreToLatest).
     *
     * @throws ValidationException
     */
    public function relocate(string $instanceId, string $targetServerId, ?string $actorId = null, bool $suspendPitr = false): void;

    /**
     * Point-in-time restore of a relocated instance to the latest point of its shipped log: a read-only copy is made
     * on its (new) server from the newest base and every segment after it.
     *
     * @return string the restore id
     *
     * @throws ValidationException no recovery point, customer-held keys, a restore already running
     */
    public function restoreToLatest(string $instanceId, ?string $actorId = null): string;

    /**
     * Drives that restore: once the copy is ready it is swapped in (it takes over the name, DNS name, databases and
     * users; the empty placeholder is retired), then PITR is turned on again for it. $retry: a swap that failed is
     * tried again.
     *
     * @return array{state: string, message: ?string} running | succeeded | failed
     */
    public function pitrProgress(string $restoreId, ?string $actorId = null, bool $retry = false): array;

    /**
     * Restore each database of a running instance from its latest restorable backup (customer-held keys are left
     * out: they need the customer's identity on the Databases page).
     *
     * @return array{restores: list<string>, skipped: list<string>} restore ids, and the databases left out (with why)
     *
     * @throws ValidationException
     */
    public function restoreLatest(string $instanceId, ?string $actorId = null): array;

    /**
     * Where a relocation stands. Without restores: `pending` (the container and its databases are being created),
     * `ready` (they exist, empty) or `failed`. With restores: `running`, `succeeded` or `failed` (with a message).
     *
     * @param  list<string>  $restoreIds  from restoreLatest; empty while the container is being created
     * @return array{state: string, message: ?string}
     */
    public function progress(string $instanceId, array $restoreIds = []): array;
}
