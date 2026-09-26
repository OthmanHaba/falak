<?php

namespace Kiln\Providers\Contracts\Data;

final readonly class Region
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $country = null,
        public bool $available = true,
    ) {}

    /**
     * @return array{id: string, name: string, country: ?string, available: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'country' => $this->country, 'available' => $this->available];
    }
}
