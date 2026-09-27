<?php

namespace Kiln\Sites\Contracts\Data;

use DateTimeImmutable;

/**
 * One version of an inline compose file.
 */
final readonly class ComposeVersionData
{
    public function __construct(
        public string $siteId,
        public int $version,
        public string $content,
        public ?string $createdBy,
        public DateTimeImmutable $createdAt,
    ) {}
}
