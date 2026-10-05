<?php

namespace Falak\Templates\Application\Catalog;

use Falak\Templates\Domain\Template;

/**
 * The curated catalog shipped with Falak (templates/<slug>/ at the repository root).
 */
interface Catalog
{
    /**
     * @return list<Template> sorted by name
     */
    public function all(): array;

    public function find(string $slug): ?Template;
}
