<?php

namespace Kiln\Templates\Application\Compose;

/**
 * Structure + policy checks of a compose file, delegated to the compose runtime's
 * `Sites\Contracts\ComposeInspector` (docs/COMPOSE_TEMPLATES.md §5).
 */
interface ComposeAnalyzer
{
    public function analyze(string $yaml): ComposeFacts;
}
