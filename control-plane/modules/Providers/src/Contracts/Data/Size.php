<?php

namespace Falak\Providers\Contracts\Data;

final readonly class Size
{
    /**
     * @param  list<string>  $regions  region ids where the size is offered (empty = all/unknown)
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $cpus,
        public int $memoryMb,
        public int $diskGb,
        public ?float $priceMonthly = null,
        public array $regions = [],
        public string $arch = 'amd64',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'cpus' => $this->cpus,
            'memory_mb' => $this->memoryMb,
            'disk_gb' => $this->diskGb,
            'price_monthly' => $this->priceMonthly,
            'regions' => $this->regions,
            'arch' => $this->arch,
        ];
    }
}
