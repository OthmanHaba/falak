<?php

namespace Falak\Projects\Infrastructure;

use Falak\Databases\Contracts\Data\DatabaseConsumer;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Projects\Contracts\Data\ResolvedVariables;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Service;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Secrets;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * `${{ service.KEY }}` resolution within one environment. Site variables may themselves contain
 * references; they are resolved recursively and cycles are reported instead of looping.
 *
 * `${{ secrets.NAME }}` resolves through the secret store, in the scope chain of the service whose variable holds
 * the reference (a site's variable referenced from another site still sees that site's own secrets).
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

    private ?string $environmentId = null;

    /** Report secret problems without reading values. */
    private bool $checkOnly = false;

    /** @var array<string, array{value: string, sensitive: bool}> resolved "serviceId|NAME" secrets */
    private array $secretValues = [];

    /** @var array<string, true> variables whose value includes a secret */
    private array $secretKeys = [];

    /** @var array<string, true> variables whose value includes a sensitive secret */
    private array $sensitiveKeys = [];

    /** @var list<bool> every secret substituted so far (its sensitive flag), in order */
    private array $marks = [];

    /** @var array<string, list<bool>> the secrets inside each resolved "serviceId.KEY" (replayed on reuse) */
    private array $nodeMarks = [];

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly DatabaseConnections $databases,
        private readonly Secrets $secrets,
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

    public function check(string $siteId, array $variables): array
    {
        $this->checkOnly = true;

        try {
            return $this->resolveForSite($siteId, $variables)->errors;
        } finally {
            $this->checkOnly = false;
        }
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
        $this->environmentId = $environmentId;
        $this->secretValues = [];
        $this->secretKeys = [];
        $this->sensitiveKeys = [];
        $this->marks = [];
        $this->nodeMarks = [];

        if ($environmentId === null) {
            return new ResolvedVariables($variables, ['The site is not part of a project environment, so ${{ service.KEY }} references cannot be resolved.'], $references);
        }

        foreach (Service::query()->where('environment_id', $environmentId)->get() as $service) {
            $this->services[Service::handle($service->name)] = $service;
        }

        $self = $this->serviceOfSite();
        $output = [];

        foreach ($variables as $key => $value) {
            $output[$key] = $this->substitute($value, (string) $key, $self !== null ? ["{$self->id}.{$key}"] : [], $self);
        }

        return new ResolvedVariables(
            $output,
            array_values(array_unique($this->errors)),
            $references,
            array_map('strval', array_keys($this->secretKeys)),
            array_map('strval', array_keys($this->sensitiveKeys)),
        );
    }

    /**
     * @param  list<string>  $stack  "serviceId.KEY" entries being resolved (cycle detection)
     * @param  Service|null  $owner  the service whose variable this is (null: the site being released, not on the canvas)
     */
    private function substitute(string $value, string $variable, array $stack, ?Service $owner): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $match) use ($variable, $stack, $owner) {
            $name = trim($match[1]);
            $key = $match[2];

            if (strtolower($name) === self::SECRETS) {
                return $this->secret($key, $match[0], $variable, $owner);
            }

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
                foreach ($this->nodeMarks[$node] ?? [] as $sensitive) {
                    $this->markSecret($variable, $sensitive);
                }

                return $this->resolved[$node];
            }

            $values = $this->valuesOf($service);

            if (! array_key_exists($key, $values)) {
                $this->errors[] = "{$variable}: service \"{$service->name}\" has no variable {$key}".($service->kind === ServiceKind::Database ? ' (it exposes '.implode(', ', array_keys($values) ?: DatabaseConnections::KEYS).')' : '').'.';

                return $match[0];
            }

            // The host of a database depends on where the site being released runs (a localhost-only engine). The SQL
            // and Redis key sets are disjoint, so the key alone tells.
            if ($service->kind === ServiceKind::Database && in_array($key, [...DatabaseConnections::HOST_KEYS, ...DatabaseConnections::REDIS_HOST_KEYS], true)
                && ($reason = $this->databases->unreachable($service->ref_id, $this->consumer())) !== null) {
                $this->errors[] = "{$variable}: {$service->name}.{$key} cannot be used here: {$reason}";

                return $match[0];
            }

            $errorsBefore = count($this->errors);
            $marksBefore = count($this->marks);
            $resolved = $service->kind === ServiceKind::Site
                ? $this->substitute($values[$key], $variable, [...$stack, $node], $service)
                : $values[$key];

            if (count($this->errors) === $errorsBefore) {
                $this->resolved[$node] = $resolved;
                $this->nodeMarks[$node] = array_slice($this->marks, $marksBefore);
            }

            return $resolved;
        }, $value);
    }

    /**
     * One `${{ secrets.NAME }}`: the secret's value, or the reference unchanged with an error.
     */
    private function secret(string $name, string $reference, string $variable, ?Service $owner): string
    {
        $chain = $owner !== null
            ? new ScopeChain($owner->organization_id, $owner->project_id, $owner->environment_id, $owner->id)
            : $this->environmentChain();

        if ($chain === null) {
            $this->errors[] = "{$variable}: {$reference} cannot be resolved outside a project environment.";

            return $reference;
        }

        $memo = ($chain->serviceId ?? $chain->environmentId).'|'.$name;

        if (isset($this->secretValues[$memo])) {
            $this->markSecret($variable, $this->secretValues[$memo]['sensitive']);

            return $this->secretValues[$memo]['value'];
        }

        $result = $this->checkOnly ? $this->secrets->check($chain, [$name]) : $this->secrets->resolve($chain, [$name]);

        if (isset($result->errors[$name])) {
            $this->errors[] = "{$variable}: {$result->errors[$name]}.";

            return $reference;
        }

        if ($this->checkOnly) {
            return $reference;
        }

        $this->secretValues[$memo] = ['value' => $result->values[$name], 'sensitive' => $result->isSensitive($name)];
        $this->markSecret($variable, $result->isSensitive($name));

        return $result->values[$name];
    }

    private function markSecret(string $variable, bool $sensitive): void
    {
        $this->marks[] = $sensitive;
        $this->secretKeys[$variable] = true;

        if ($sensitive) {
            $this->sensitiveKeys[$variable] = true;
        }
    }

    /** The scope chain of the environment itself, for a site that is not on its canvas. */
    private function environmentChain(): ?ScopeChain
    {
        $environment = $this->environmentId !== null ? Environment::query()->find($this->environmentId) : null;

        return $environment === null ? null : new ScopeChain($environment->organization_id, $environment->project_id, $environment->id);
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
