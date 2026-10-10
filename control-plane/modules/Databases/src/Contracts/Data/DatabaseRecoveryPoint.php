<?php

namespace Falak\Databases\Contracts\Data;

use DateTimeImmutable;

/**
 * A database's latest restorable backup and how it is protected (DatabaseRecovery).
 */
final readonly class DatabaseRecoveryPoint
{
    public function __construct(
        public string $databaseId,
        public string $organizationId,
        public string $instanceId,
        public string $instanceName,
        public string $name,
        /** mysql | mariadb | postgresql | redis | valkey */
        public string $engine,
        public ?string $siteId,
        public ?string $backupId,
        /** When the latest restorable backup finished (null: none) */
        public ?DateTimeImmutable $lastBackupAt,
        /** The latest backup's key is the customer's (a restore needs their age identity) */
        public bool $customerHeld,
        /** A restore drill passed on one of its backups */
        public ?DateTimeImmutable $drilledAt,
        /** An enabled backup schedule covers the instance */
        public bool $scheduled,
        public bool $pitrEnabled,
        /** The engine can do point-in-time recovery (SQL engines) */
        public bool $pitrSupported,
    ) {}

    /** Seconds of writes lost when restoring the latest backup now (null: no backup, everything is lost). */
    public function dataLossSeconds(?DateTimeImmutable $now = null): ?int
    {
        return $this->lastBackupAt !== null ? max(0, ($now ?? new DateTimeImmutable)->getTimestamp() - $this->lastBackupAt->getTimestamp()) : null;
    }
}
