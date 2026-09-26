<?php

namespace Kiln\Sites\Contracts\Data;

final readonly class LaravelSettings
{
    public function __construct(
        public bool $scheduler = false,
        public bool $horizon = false,
        public bool $octane = false,
        public bool $maintenance = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            scheduler: (bool) ($data['scheduler'] ?? false),
            horizon: (bool) ($data['horizon'] ?? false),
            octane: (bool) ($data['octane'] ?? false),
            maintenance: (bool) ($data['maintenance'] ?? false),
        );
    }

    /**
     * @return array{scheduler: bool, horizon: bool, octane: bool, maintenance: bool}
     */
    public function toArray(): array
    {
        return ['scheduler' => $this->scheduler, 'horizon' => $this->horizon, 'octane' => $this->octane, 'maintenance' => $this->maintenance];
    }
}
