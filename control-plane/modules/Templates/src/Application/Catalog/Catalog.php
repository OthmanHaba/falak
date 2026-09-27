<?php

namespace Kiln\Templates\Application\Catalog;

use Kiln\Templates\Domain\Template;

/**
 * The curated catalog shipped with Kiln (templates/<slug>/ at the repository root).
 */
interface Catalog
{
    /**
     * @return list<Template> sorted by name
     */
    public function all(): array;

    public function find(string $slug): ?Template;
}
