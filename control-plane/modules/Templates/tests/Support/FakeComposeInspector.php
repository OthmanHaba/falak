<?php

namespace Kiln\Templates\Tests\Support;

use Kiln\Sites\Contracts\ComposeInspector;
use Kiln\Sites\Contracts\Data\ComposeServiceSummary;
use Kiln\Sites\Contracts\Data\ComposeSummary;
use Kiln\Templates\Application\Compose\ComposeDocument;

/**
 * `Sites\Contracts\ComposeInspector` double: services and ports from the parsed document, configurable policy
 * violations, records what it parsed.
 */
final class FakeComposeInspector implements ComposeInspector
{
    /** @var list<string> */
    public array $violations = [];

    /** @var list<string> */
    public array $parsed = [];

    public function parse(string $yaml): ComposeSummary
    {
        $this->parsed[] = $yaml;
        $compose = ComposeDocument::parse($yaml);

        return new ComposeSummary(
            array_map(fn (string $name) => new ComposeServiceSummary($name, null, false, $compose->containerPorts($name), [], [], [], false), $compose->serviceNames()),
            array_map('strval', array_keys((array) ($compose->data['volumes'] ?? []))),
            $this->violations,
            [],
        );
    }
}
