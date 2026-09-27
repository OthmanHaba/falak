<?php

namespace Kiln\Templates\Infrastructure;

use Illuminate\Contracts\Container\Container;
use Kiln\Sites\Contracts\ComposeInspector;
use Kiln\Templates\Application\Compose\ComposeAnalyzer;
use Kiln\Templates\Application\Compose\ComposeFacts;
use Kiln\Templates\Domain\InvalidTemplate;
use Throwable;

/**
 * {@see ComposeAnalyzer} backed by the compose runtime's `Sites\Contracts\ComposeInspector::parse()` (lane A,
 * docs/COMPOSE_TEMPLATES.md §5).
 *
 * The ComposeSummary is read tolerantly (array or object; `services` as a map or a list of `{name, ports}`;
 * `violations` / `policy_violations` as strings or `{message}`) so this adapter does not break on small shape
 * differences. Once lane A has merged, tighten {@see self::facts()} to the real ComposeSummary type.
 */
final class InspectorComposeAnalyzer implements ComposeAnalyzer
{
    public function __construct(private readonly Container $container) {}

    public function analyze(string $yaml): ComposeFacts
    {
        /** @var ComposeInspector $inspector */
        $inspector = $this->container->make(ComposeInspector::class);

        try {
            $summary = $inspector->parse($yaml);
        } catch (InvalidTemplate $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidTemplate(['compose.yaml: '.$e->getMessage()]);
        }

        return self::facts($summary);
    }

    public static function facts(mixed $summary): ComposeFacts
    {
        $services = [];

        foreach ((array) self::read($summary, ['services']) as $key => $service) {
            $name = is_string($key) ? $key : (string) self::read($service, ['name']);

            if ($name === '') {
                continue;
            }

            $ports = self::read($service, ['ports', 'exposed_ports', 'exposedPorts', 'expose']) ?? (is_array($service) && array_is_list($service) ? $service : []);
            $services[$name] = array_values(array_unique(array_filter(array_map(
                fn ($port) => (int) (is_array($port) || is_object($port) ? self::read($port, ['target', 'container', 'port']) : $port),
                (array) $ports,
            ))));
        }

        $volumes = array_values(array_map(
            fn ($volume, $key) => is_string($key) ? $key : (string) (is_scalar($volume) ? $volume : self::read($volume, ['name'])),
            (array) (self::read($summary, ['volumes', 'named_volumes', 'namedVolumes']) ?? []),
            array_keys((array) (self::read($summary, ['volumes', 'named_volumes', 'namedVolumes']) ?? [])),
        ));

        $violations = array_values(array_map(
            fn ($violation) => is_scalar($violation) ? (string) $violation : (string) (self::read($violation, ['message', 'reason']) ?? json_encode($violation)),
            (array) (self::read($summary, ['violations', 'policy_violations', 'policyViolations', 'errors']) ?? []),
        ));

        return new ComposeFacts($services, $volumes, $violations);
    }

    /**
     * @param  list<string>  $keys
     */
    private static function read(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }

            if (is_object($source)) {
                if (isset($source->{$key})) {
                    return $source->{$key};
                }

                if (method_exists($source, $key)) {
                    return $source->{$key}();
                }
            }
        }

        return null;
    }
}
