<?php

namespace Falak\Sites\Contracts\Data;

use Falak\Sites\Contracts\OctaneServer;

/**
 * Laravel toggles of a site. Octane: `octaneServer` and `octanePort` are set by Sites when Octane is
 * enabled — the port is unique among the sites of every server the site targets and persisted, so Edge
 * (reverse_proxy 127.0.0.1:<port>) and Processes (octane:start --port=<port>) always agree on it.
 */
final readonly class LaravelSettings
{
    /**
     * Offset of the second loopback port an Octane server needs: FrankenPHP's Caddy admin API
     * (`--admin-port`, which would otherwise clash with the edge's :2019) or RoadRunner's RPC port.
     */
    public const OCTANE_AUX_PORT_OFFSET = 10000;

    public function __construct(
        public bool $scheduler = false,
        public bool $horizon = false,
        public bool $octane = false,
        public bool $maintenance = false,
        public ?OctaneServer $octaneServer = null,
        public ?int $octanePort = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $port = $data['octane_port'] ?? null;

        return new self(
            scheduler: (bool) ($data['scheduler'] ?? false),
            horizon: (bool) ($data['horizon'] ?? false),
            octane: (bool) ($data['octane'] ?? false),
            maintenance: (bool) ($data['maintenance'] ?? false),
            octaneServer: is_string($data['octane_server'] ?? null) ? OctaneServer::tryFrom($data['octane_server']) : null,
            octanePort: is_numeric($port) ? (int) $port : null,
        );
    }

    /**
     * @return array{scheduler: bool, horizon: bool, octane: bool, maintenance: bool, octane_server: ?string, octane_port: ?int}
     */
    public function toArray(): array
    {
        return [
            'scheduler' => $this->scheduler,
            'horizon' => $this->horizon,
            'octane' => $this->octane,
            'maintenance' => $this->maintenance,
            'octane_server' => $this->octaneServer?->value,
            'octane_port' => $this->octanePort,
        ];
    }

    /** Octane is on and has its port: the site can be served by Octane. */
    public function servesOctane(): bool
    {
        return $this->octane && $this->octanePort !== null;
    }

    public function octaneAuxPort(): ?int
    {
        return $this->octanePort !== null ? $this->octanePort + self::OCTANE_AUX_PORT_OFFSET : null;
    }

    public function with(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
