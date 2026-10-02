<?php

namespace Kiln\Sites\Application\Compose;

/**
 * The Docker networks a compose service is on, by their real names: what a service run as its own Kiln site joins so
 * the stack's services and it keep resolving each other by name (docs/COMPOSE_TEMPLATES.md §1.7).
 *
 * Compose names a project network `<project>_<network>` (the project is the stack's slug), unless the top-level
 * definition gives a `name:` or marks it `external` (then the name is used as is; the legacy `external: {name: x}` names
 * it x; a name can read the stack's variables, `${NETWORK}`). A service without `networks:` is on `<project>_default`;
 * one with a `network_mode` is on none of them (so it joins none: not the default network either).
 *
 * The agent joins at most {@see MAX} networks with Docker-safe names (deploy.container.swap / docker.run schemas):
 * {@see check()} sorts out the others when the service is split out, so they are reported once instead of failing
 * every deploy.
 */
final class ComposeNetworks
{
    /** Networks a container joins at most (agent protocol: networks maxItems). */
    public const MAX = 8;

    /** Aliases a container has on one network at most (agent protocol: networks[].aliases maxItems). */
    public const MAX_ALIASES = 8;

    /** Network names the agent accepts (agent protocol: networks[].name). */
    private const NAME = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/';

    /** Network aliases the agent accepts (agent protocol: networks[].aliases[]). */
    private const ALIAS = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/';

    /**
     * The real names of the networks a service is on (none for a `network_mode`). Names are read as the stack's
     * Compose resolves them: `name: ${NETWORK}` with the stack's variables.
     *
     * @param  array<string, mixed>  $document  the parsed (merged) compose project
     * @param  array<string, string>  $variables  the stack's variables (its `.env`)
     * @return list<string>
     */
    public static function of(array $document, string $project, string $service, array $variables = []): array
    {
        return array_map('strval', array_keys(self::joins($document, $project, $service, $variables)));
    }

    /**
     * The aliases a service declares per network (`networks: {back: {aliases: [app]}}`), by the network's real name;
     * networks without any are left out. The stack's services may reach the service by these names.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, string>  $variables
     * @return array<string, list<string>>
     */
    public static function aliases(array $document, string $project, string $service, array $variables = []): array
    {
        return array_filter(self::joins($document, $project, $service, $variables), fn (array $aliases) => $aliases !== []);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, string>  $variables
     * @return array<string, list<string>> real name => declared aliases
     */
    private static function joins(array $document, string $project, string $service, array $variables): array
    {
        $definition = $document['services'][$service] ?? null;

        if (! is_array($definition) || isset($definition['network_mode'])) {
            return [];
        }

        // networks: [a, b] or networks: {a: {aliases: [x]}, b: null}
        $declared = is_array($definition['networks'] ?? null) ? $definition['networks'] : [];
        $entries = [];
        foreach (array_is_list($declared) ? array_fill_keys(array_map('strval', array_filter($declared, 'is_scalar')), null) : $declared as $name => $config) {
            if ((string) $name !== '') {
                $entries[(string) $name] = is_array($config) ? $config : [];
            }
        }

        if ($entries === []) {
            $entries = ['default' => []];
        }

        $top = is_array($document['networks'] ?? null) ? $document['networks'] : [];
        $joins = [];

        foreach ($entries as $name => $config) {
            $real = self::realName((string) $name, is_array($top[$name] ?? null) ? $top[$name] : [], $project, $variables);
            $aliases = array_map(fn ($alias) => ComposeInterpolation::apply((string) $alias, $variables), array_filter((array) ($config['aliases'] ?? []), 'is_scalar'));
            $joins[$real] = array_values(array_unique([...($joins[$real] ?? []), ...array_filter($aliases, fn (string $alias) => $alias !== '')]));
        }

        return $joins;
    }

    /**
     * @param  array<string, mixed>  $definition  the top-level network definition
     * @param  array<string, string>  $variables
     */
    private static function realName(string $name, array $definition, string $project, array $variables): string
    {
        $external = $definition['external'] ?? false;
        $given = match (true) {
            is_string($definition['name'] ?? null) && $definition['name'] !== '' => $definition['name'],
            // Compose file format 2/3: external: { name: x }.
            is_array($external) && is_string($external['name'] ?? null) && $external['name'] !== '' => $external['name'],
            default => null,
        };

        return match (true) {
            $given !== null => ComposeInterpolation::apply($given, $variables),
            $external === true || is_array($external) => $name,
            default => "{$project}_{$name}",
        };
    }

    /**
     * The networks a container can join, and the ones it can't: names Docker (and the agent) doesn't accept, and any
     * beyond the first {@see MAX}.
     *
     * @param  list<string>  $names
     * @return array{networks: list<string>, skipped: list<string>}
     */
    public static function check(array $names): array
    {
        $valid = array_values(array_filter($names, fn (string $name) => preg_match(self::NAME, $name) === 1));

        return [
            'networks' => array_slice($valid, 0, self::MAX),
            'skipped' => [...array_values(array_diff($names, $valid)), ...array_slice($valid, self::MAX)],
        ];
    }

    /** Whether a compose service's name can be its network alias. */
    public static function validAlias(string $alias): bool
    {
        return preg_match(self::ALIAS, $alias) === 1;
    }
}
