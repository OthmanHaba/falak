<?php

namespace Falak\Volumes\Contracts\Data;

use DateTimeImmutable;

/**
 * A volume's latest restorable backup and how it is protected (VolumeRecovery).
 */
final readonly class VolumeRecoveryPoint
{
    /**
     * @param  list<string>  $siteIds  the sites it is attached to
     */
    public function __construct(
        public string $volumeId,
        public string $organizationId,
        public ?string $serverId,
        public string $name,
        public string $kind,
        public array $siteIds,
        public ?string $backupId,
        public ?DateTimeImmutable $lastBackupAt,
        public bool $customerHeld,
        public ?DateTimeImmutable $drilledAt,
        /** An enabled backup schedule covers it */
        public bool $scheduled,
    ) {}

    /** Seconds of writes lost when restoring the latest backup now (null: no backup). */
    public function dataLossSeconds(?DateTimeImmutable $now = null): ?int
    {
        return $this->lastBackupAt !== null ? max(0, ($now ?? new DateTimeImmutable)->getTimestamp() - $this->lastBackupAt->getTimestamp()) : null;
    }
}
