<?php

namespace Kiln\Identity\Contracts\Data;

final readonly class OrganizationData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $ownerId,
        public bool $personal,
    ) {}

    /**
     * @return array{id: string, name: string, slug: string, personal: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'personal' => $this->personal];
    }
}
