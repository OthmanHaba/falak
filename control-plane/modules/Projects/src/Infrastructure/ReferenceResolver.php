<?php

namespace Kiln\Projects\Infrastructure;

use Kiln\Databases\Contracts\Data\DatabaseConsumer;
use Kiln\Databases\Contracts\DatabaseConnections;
use Kiln\Projects\Contracts\Data\ResolvedVariables;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Contracts\VariableReferences;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * `${{ service.KEY }}` resolution within one environment. Site variables may themselves contain
 * references; they are resolved recursively and cycles are reported instead of looping.
 */
final class ReferenceResolver implements VariableReferences
{
    /** @var array<string, Service> services of the environment being resolved, keyed by handle */
    private array $services = [];

    /** @var array<string, array<string, string>> raw variables per service id */
    private array $values = [];

    /** @var array<string, string> resolved "serviceId.KEY" values */
    private array $resolved = [];

    /** @var list<string> */
    private array $errors = [];

    private ?string $siteId = null;

    /** @var array<string, string> */
    private array $siteVariables = [];

    private ?DatabaseConsumer $consumer = null;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly DatabaseConnections $databases,
    ) {}

    public function resolve(string $environmentId, string $siteId, array $variables): ResolvedVariables
    {
        return $this->run($environmentId, $siteId, $variables);
    }

    public function resolveForSite(string $siteId, array $variables): ResolvedVariables
    {
        $environmentId = Service::query()->where('kind', ServiceKind::Site)->where('ref_id', strtolower($siteId))->value('environment_id');

        return $this->run($environmentId !== null ? (string) $environmentId : null, $siteId, $variables);
    }

    public function referencesIn(array $variables): array
    {
        $references = [];

        foreach ($variables as $variable => $value) {
            if (preg_match_all(self::PATTERN, (string) $value, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $references[] = ['service' => trim($match[1]), 'key' => $match[2], 'variable' => (string) $variable];
                }
            }
        }

        return $references;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function run(?string $environmentId, string $siteId, array $variables): ResolvedVariables
    {
        $variables = array_map('strval', $variables);
        $references = array_map(fn (array $r) => ['service' => $r['service'], 'key' => $r['key']], $this->referencesIn($variables));

        if ($references === []) {
            return new ResolvedVariables($variables, [], []);
        }

        $this->services = [];
        $this->values = [];
        $this->resolved = [];
        $this->errors = [];
        $this->siteId = strtolower($siteId);
        $this->siteVariables = $variables;
        $this->consumer = null;

        if ($environmentId === null) {
            return new ResolvedVariables($variables, ['The site is not part of a project environment, so ${{ service.KEY }} references cannot be resolved.'], $references);
        }

        foreach (Service::query()->where('environment_id', $environmentId)->get() as $service) {
            $this->services[Service::handle($service->name)] = $service;
        }

        $self = $this->serviceOfSite();
        $output = [];

        foreach ($variables as $key => $value) {
            $output[$key] = $this->substitute($value, (string) $key, $self !== null ? ["{$self->id}.{$key}"] : []);
        }

        return new ResolvedVariables($output, array_values(array_unique($this->errors)), $references);
    }

    /**
     * @param  list<string>  $stack  "serviceId.KEY" entries being resolved (cycle detection)
     */
    private function substitute(string $value, string $variable, array $stack): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $match) use ($variable, $stack) {
            $name = trim($match[1]);
            $key = $match[2];
            $service = $this->services[Service::handle($name)] ?? null;

            if ($service === null) {
                $this->errors[] = "{$variable}: unknown service \"{$name}\" in {$match[0]}.";

                return $match[0];
            }

            $node = "{$service->id}.{$key}";

            if (in_array($node, $stack, true)) {
                $this->errors[] = "{$variable}: reference cycle ".$this->describe([...$stack, $node]).'.';

                return $match[0];
            }

            if (array_key_exists($node, $this->resolved)) {
                return $this->resolved[$node];
            }

            $values = $this->valuesOf($service);

            if (! array_key_exists($key, $values)) {
                $this->errors[] = "{$variable}: service \"{$service->name}\" has no variable {$key}".($service->kind === ServiceKind::Database ? ' (it exposes '.implode(', ', array_keys($values) ?: DatabaseConnections::KEYS).')' : '').'.';

                return $match[0];
            }

            // The host of a database depends on where the site being released runs (a localhost-only engine).
            if ($service->kind === ServiceKind::Database && in_array($key, DatabaseConnections::HOST_KEYS, true)
                && ($reason = $this->databases->unreachable($service->ref_id, $this->consumer())) !== null) {
                $this->errors[] = "{$variable}: {$service->name}.{$key} cannot be used here: {$reason}";

                return $match[0];
            }

            $errorsBefore = count($this->errors);
            $resolved = $service->kind === ServiceKind::Site
                ? $this->substitute($values[$key], $variable, [...$stack, $node])
                : $values[$key];

            if (count($this->errors) === $errorsBefore) {
                $this->resolved[$node] = $resolved;
            }

            return $resolved;
        }, $value);
    }

    /**
     * @return array<string, string>
     */
    private function valuesOf(Service $service): array
    {
        if ($service->kind === ServiceKind::Site && $service->ref_id === $this->siteId) {
            return $this->siteVariables;
        }

        return $this->values[$service->id] ??= match ($service->kind) {
            ServiceKind::Site => array_map('strval', $this->sites->environment($service->ref_id)?->variables ?? []),
            ServiceKind::Database => $this->databases->variables($service->ref_id, $this->consumer()),
        };
    }

    /** The site being released, as the database consumer (references in other sites' variables resolve for it too). */
    private function consumer(): DatabaseConsumer
    {
        if ($this->consumer !== null) {
            return $this->consumer;
        }

        $site = $this->siteId !== null ? $this->sites->find($this->siteId) : null;

        return $this->consumer = new DatabaseConsumer(
            name: $site?->name ?? 'The site',
            serverIds: $site?->serverIds() ?? [],
            containerized: $site?->runtime->usesDocker() ?? false,
        );
    }

    private function serviceOfSite(): ?Service
    {
        foreach ($this->services as $service) {
            if ($service->kind === ServiceKind::Site && $service->ref_id === $this->siteId) {
                return $service;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $stack
     */
    private function describe(array $stack): string
    {
        $names = [];

        foreach ($this->services as $service) {
            $names[$service->id] = $service->name;
        }

        return implode(' → ', array_map(function (string $node) use ($names) {
            [$id, $key] = explode('.', $node, 2);

            return ($names[$id] ?? '?').'.'.$key;
        }, $stack));
    }
}
