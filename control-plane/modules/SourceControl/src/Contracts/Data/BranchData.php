<?php

namespace Kiln\SourceControl\Contracts\Data;

final readonly class BranchData
{
    public function __construct(
        public string $name,
        public ?string $sha,
        public bool $protected = false,
    ) {}
}
