<?php

namespace Kiln\Sites\Application\Compose;

/**
 * The Docker networks a compose service is on, by their real names: what a service run as its own Kiln site joins so
 * the stack's services and it keep resolving each other by name (docs/COMPOSE_TEMPLATES.md §1.7).
 *
 * Compose names a project network `<project>_<network>` (the project is the stack's slug), unless the top-level
 * definition gives a `name:` or marks it `external` (then the name is used as is). A service without `networks:` is
 * on `<project>_default`; one with a `network_mode` is on none of them.
 */
final class ComposeNetworks
{
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

            return match (true) {
                isset($definition['name']) && is_string($definition['name']) && $definition['name'] !== '' => $definition['name'],
                ($definition['external'] ?? false) === true => $name,
                default => "{$project}_{$name}",
            };
        }, $names)));
    }
}
