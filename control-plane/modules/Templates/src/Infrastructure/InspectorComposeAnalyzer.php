<?php

namespace Falak\Templates\Infrastructure;

use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\Data\ComposeServiceSummary;
use Falak\Sites\Contracts\Data\ComposeSummary;
use Falak\Templates\Application\Compose\ComposeAnalyzer;
use Falak\Templates\Application\Compose\ComposeFacts;

/**
 * {@see ComposeAnalyzer} backed by the compose runtime's `Sites\Contracts\ComposeInspector::parse()`
 * (docs/COMPOSE_TEMPLATES.md §5): structure errors and default-policy violations both count against a template.
 */
final class InspectorComposeAnalyzer implements ComposeAnalyzer
{
    public function __construct(private readonly ComposeInspector $inspector) {}

    public function analyze(string $yaml): ComposeFacts
    {
        return self::facts($this->inspector->parse($yaml));
    }

    public static function facts(ComposeSummary $summary): ComposeFacts
    {
        $services = [];

        foreach ($summary->services as $service) {
            /** @var ComposeServiceSummary $service */
            $services[$service->name] = $service->ports;
        }

        return new ComposeFacts($services, $summary->volumes, [...$summary->errors, ...$summary->violations]);
    }
}
