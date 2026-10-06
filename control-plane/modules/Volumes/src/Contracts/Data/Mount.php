<?php

namespace Falak\Volumes\Contracts\Data;

/**
 * A volume mounted into a container: docker.run / deploy.container.swap `volumes[]`.
 */
final readonly class Mount
{
    /**
     * @param  string  $source  the Docker volume's name, or the host path (sized volumes: their mountpoint)
     * @param  string  $target  absolute path in the container
     */
    public function __construct(
        public string $volumeId,
        public string $source,
        public string $target,
        public bool $readOnly = false,
    ) {}

    /**
     * @return array{source: string, target: string, read_only?: true}
     */
    public function toPayload(): array
    {
        return array_filter(['source' => $this->source, 'target' => $this->target, 'read_only' => $this->readOnly ?: null], fn ($v) => $v !== null);
    }
}
