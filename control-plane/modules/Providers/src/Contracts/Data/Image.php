<?php

namespace Falak\Providers\Contracts\Data;

final readonly class Image
{
    public function __construct(
        public string $id,
        public string $name,
        public string $distribution = 'ubuntu',
        public ?string $version = null,
        public ?string $arch = null,
    ) {}

    /**
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'distribution' => $this->distribution, 'version' => $this->version, 'arch' => $this->arch];
    }
}
