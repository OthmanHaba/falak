<?php

namespace Kiln\Sites\Application\Compose;

/**
 * The Docker networks a compose service is on, by their real names: what a service run as its own Kiln site joins so
 * the stack's services and it keep resolving each other by name (docs/COMPOSE_TEMPLATES.md §1.7).
 *
 * Compose names a project network `<project>_<network>` (the project is the stack's slug), unless the top-level
 * definition gives a `name:` or marks it `external` (then the name is used as is; the legacy `external: {name: x}` names
 * it x). A service without `networks:` is on `<project>_default`; one with a `network_mode` is on none of them.
 *
 * The agent joins at most {@see MAX} networks with Docker-safe names (deploy.container.swap / docker.run schemas):
 * {@see check()} sorts out the others when the service is split out, so they are reported once instead of failing
 * every deploy.
 */
final class ComposeNetworks
{
    /** Networks a container joins at most (agent protocol: networks maxItems). */
    public const MAX = 8;

    /** Network names the agent accepts (agent protocol: networks[].name). */
    private const NAME = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/';

    /** Network aliases the agent accepts (agent protocol: networks[].aliases[]). */
    private const ALIAS = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/';

    /**
     * @param  array<string, mixed>  $document  the parsed (merged) compose project
     * @return list<string>
     */
    public static function of(array $document, string $project, string $service): array
    {
        $definition = $document['services'][$service] ?? null;

        if (! is_array($definition) || isset($definition['network_mode'])) {
            return [];
        }

        $declared = $definition['networks'] ?? [];
        $names = is_array($declared) ? (array_is_list($declared) ? $declared : array_keys($declared)) : [];
        $names = array_values(array_filter(array_map('strval', $names), fn (string $name) => $name !== ''));

        if ($names === []) {
            $names = ['default'];
        }

        $top = is_array($document['networks'] ?? null) ? $document['networks'] : [];

        return array_values(array_unique(array_map(function (string $name) use ($top, $project) {
            $definition = is_array($top[$name] ?? null) ? $top[$name] : [];

            $external = $definition['external'] ?? false;

            return match (true) {
                isset($definition['name']) && is_string($definition['name']) && $definition['name'] !== '' => $definition['name'],
                // Compose file format 2/3: external: { name: x }.
                is_array($external) && isset($external['name']) && is_string($external['name']) && $external['name'] !== '' => $external['name'],
                $external === true || is_array($external) => $name,
                default => "{$project}_{$name}",
            };
        }, $names)));
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
