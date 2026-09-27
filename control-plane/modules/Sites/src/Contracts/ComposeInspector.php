<?php

namespace Kiln\Sites\Contracts;

use Kiln\Sites\Contracts\Data\ComposeSummary;

/**
 * Parses compose files: services, exposed ports, volumes and policy violations (docs/COMPOSE_TEMPLATES.md §1.3, §5).
 * Never throws for bad input — problems are reported in the summary's `errors`.
 */
interface ComposeInspector
{
    public function parse(string $yaml): ComposeSummary;
}
