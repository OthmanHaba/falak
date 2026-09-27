<?php

namespace Kiln\Templates\Tests\Support;

use Kiln\Templates\Application\Compose\ComposeDocument;

/**
 * Stand-in for lane A's `Sites\Contracts\ComposeInspector` (docs/COMPOSE_TEMPLATES.md §5): duck-typed (the
 * interface does not exist before lane A merges) and bound under its container key, so templates code takes the
 * "real inspector" path. Returns a summary-shaped object with configurable policy violations.
 */
final class FakeComposeInspector
{
    /** @var list<string> */
    public array $violations = [];

    /** @var list<string> */
    public array $parsed = [];

    public function parse(string $yaml): object
    {
        $this->parsed[] = $yaml;
        $compose = ComposeDocument::parse($yaml);

        return (object) [
            'services' => array_map(fn (string $name) => (object) ['name' => $name, 'ports' => $compose->containerPorts($name)], $compose->serviceNames()),
            'volumes' => array_keys((array) ($compose->data['volumes'] ?? [])),
            'violations' => array_map(fn (string $message) => (object) ['message' => $message], $this->violations),
        ];
    }
}
