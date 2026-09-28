<?php

namespace Kiln\Fleet\Contracts\Data;

/**
 * The agent build a server runs versus the one this control plane ships (`/install/agent/linux-<arch>`).
 */
final readonly class AgentVersionInfo
{
    public function __construct(
        public string $serverId,
        public ?string $version,
        public ?string $availableVersion,
        public bool $updateAvailable,
        public ?AgentUpgradeData $upgrade = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'available_version' => $this->availableVersion,
            'update_available' => $this->updateAvailable,
            'upgrade' => $this->upgrade?->toArray(),
        ];
    }
}
