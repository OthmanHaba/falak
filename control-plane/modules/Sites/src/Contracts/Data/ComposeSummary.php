<?php

namespace Falak\Sites\Contracts\Data;

use Falak\Sites\Contracts\ComposeInspector;

/**
 * What {@see ComposeInspector::parse()} found in a compose file.
 */
final readonly class ComposeSummary
{
    /**
     * @param  list<ComposeServiceSummary>  $services  in file order
     * @param  list<string>  $volumes  top-level named volumes
     * @param  list<string>  $violations  policy violations (§1.3); allowed only with the organization's "Allow privileged compose"
     * @param  list<string>  $errors  the file cannot be used (YAML/structure errors)
     * @param  list<string>  $warnings  usable, but Falak changes or ignores something (e.g. host ports)
     * @param  array<string, array{name: ?string, external: bool}>  $volumeDefinitions  top-level named volumes: their
     *                                                                                  explicit Docker `name:` and `external`
     */
    public function __construct(
        public array $services,
        public array $volumes,
        public array $violations,
        public array $errors,
        public array $warnings = [],
        public array $volumeDefinitions = [],
    ) {}

    /** Parses and has at least one service. */
    public function valid(): bool
    {
        return $this->errors === [];
    }

    /** Valid and within the default (non-privileged) policy. */
    public function passesPolicy(): bool
    {
        return $this->valid() && $this->violations === [];
    }

    public function service(string $name): ?ComposeServiceSummary
    {
        foreach ($this->services as $service) {
            if ($service->name === $name) {
                return $service;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function serviceNames(): array
    {
        return array_map(fn (ComposeServiceSummary $service) => $service->name, $this->services);
    }

    /**
     * @return list<string> services with a `build:` section
     */
    public function buildServices(): array
    {
        return array_values(array_map(fn (ComposeServiceSummary $s) => $s->name, array_filter($this->services, fn (ComposeServiceSummary $s) => $s->build)));
    }

    /**
     * @return array{services: list<array<string, mixed>>, volumes: list<string>, violations: list<string>, errors: list<string>, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'services' => array_map(fn (ComposeServiceSummary $service) => $service->toArray(), $this->services),
            'volumes' => $this->volumes,
            'violations' => $this->violations,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
