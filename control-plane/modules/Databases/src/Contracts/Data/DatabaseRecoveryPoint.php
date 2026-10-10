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
        /** How far the instance's shipped log reaches (PITR on and a recoverable chain), null otherwise */
        public ?DateTimeImmutable $pitrLatestAt = null,
        /** That chain's keys are the customer's (a PITR restore needs their age identity) */
        public bool $pitrCustomerHeld = false,
    ) {}

    /** A lost server's copy comes back by PITR to the latest point (else from the latest backup). */
    public function usesPitr(): bool
    {
        return $this->pitrEnabled && $this->pitrLatestAt !== null && ! $this->pitrCustomerHeld;
    }

    /**
     * Seconds of writes lost when recovering now: the PITR lag (time since the last shipped log) when PITR can be used,
     * else the latest backup's age. Null: no backup, everything is lost.
     */
    public function dataLossSeconds(?DateTimeImmutable $now = null): ?int
    {
        $point = $this->usesPitr() ? $this->pitrLatestAt : $this->lastBackupAt;

        return $point !== null ? max(0, ($now ?? new DateTimeImmutable)->getTimestamp() - $point->getTimestamp()) : null;
    }
}
